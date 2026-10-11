<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Contracts\JournalPoster;
use App\Models\SupplierGroup;
use App\Models\SupplierMaster;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\Journal\PostingRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Database\Seeders\FinanceChartOfAccountsSeeder;
use Modules\Finance\Database\Seeders\FinanceGlAccountsSeeder;
use Modules\Finance\Enums\AccountRole;
use Modules\Finance\Models\AccountDefault;
use Modules\Finance\Models\JournalEntry;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Services\JournalPostingService;
use Modules\Finance\Services\TrialBalanceService;
use Modules\Inventory\Models\CustomerMaster;
use Modules\Inventory\Models\GoodsReceivedNote;
use Modules\Inventory\Models\GoodsReceivedNoteItem;
use Modules\Inventory\Models\Invoice;
use Modules\Inventory\Models\InvoiceItem;
use Modules\Inventory\Posting\GoodsReceivedNoteJournal;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The two documents that create the balances, rather than settle them.
 *
 * SupplierPaymentPostingTest and CustomerReceiptPostingTest cover the money
 * going out and coming in. These cover the GRN and the Invoice, where the debt
 * is first recorded -- and in particular the TAX SPLIT, which is the part most
 * easily got wrong because it balances either way.
 *
 * Tax collected from a customer is not income and tax paid to a supplier is not
 * an expense. Roll either into its revenue or purchase account and the journal
 * still balances perfectly, the Trial Balance still agrees, and the tax return
 * is simply wrong with nothing to indicate it.
 */
class DocumentPostingTest extends TestCase
{
    use RefreshDatabase;

    private User           $user;
    private SupplierMaster $supplier;
    private CustomerMaster $customer;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        app(SettingsService::class)->set('module.inventory', true);
        app(SettingsService::class)->set('module.finance', true);
        app(SettingsService::class)->set(
            JournalPostingService::SETTING_POSTING_MODE,
            JournalPostingService::MODE_IMMEDIATE,
        );

        foreach (['view_invoices', 'create_invoices', 'edit_invoices', 'delete_invoices'] as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['inventory', 'finance']]);
        $this->user->givePermissionTo(['view_invoices', 'create_invoices', 'edit_invoices', 'delete_invoices']);

        $group = SupplierGroup::firstOrCreate(
            ['name' => 'Trade (Local)'],
            ['code' => 'TRADE', 'is_active' => true],
        );

        $this->supplier = SupplierMaster::create([
            'supplier_code'     => 'SUP-0001',
            'supplier_name'     => 'ABC Textiles',
            'supplier_group_id' => $group->id,
        ]);

        $this->customer = CustomerMaster::create([
            'customer_code' => 'CUS-0001',
            'customer_name' => 'Nimal Stores',
            'customer_type' => 'Retail',
        ]);

        $this->seed(FinanceChartOfAccountsSeeder::class);
        $this->seed(FinanceGlAccountsSeeder::class);
    }

    // ── GRN: the input tax side ─────────────────────────────────────────────

    /**
     * 100,000 of goods at 18% tax. Purchases must carry 100,000 and Input Tax
     * Recoverable 18,000 -- not 118,000 against Purchases alone.
     */
    public function test_a_grn_splits_input_tax_out_of_purchases(): void
    {
        $grn = $this->confirmedGrn([
            ['quantity_received' => 1, 'unit_price' => 100000, 'tax' => 18, 'line_total' => 118000],
        ], total: 118000);

        app(JournalPoster::class)->post(GoodsReceivedNoteJournal::build($grn));

        $this->assertSame(100000.0, $this->balance(AccountRole::Purchases), 'Purchases is net of tax.');
        $this->assertSame(18000.0, $this->balance(AccountRole::InputTaxRecoverable), 'Tax is recoverable, not a cost.');
        $this->assertSame(-118000.0, $this->balance(AccountRole::AccountsPayable), 'We owe the supplier the full amount.');

        $this->assertTrue(app(TrialBalanceService::class)->build()['totals']['balanced']);
    }

    /** A client who is not tax registered has 0% lines and gets no tax line. */
    public function test_a_grn_with_no_tax_posts_no_tax_line(): void
    {
        $grn = $this->confirmedGrn([
            ['quantity_received' => 2, 'unit_price' => 25000, 'tax' => 0, 'line_total' => 50000],
        ], total: 50000);

        app(JournalPoster::class)->post(GoodsReceivedNoteJournal::build($grn));

        $journal = $this->journalFor(PostingRole::SOURCE_GRN, $grn->id);

        $this->assertCount(2, $journal->lines, 'Dr Purchases, Cr Payable. Nothing else.');
        $this->assertSame(0.0, $this->balance(AccountRole::InputTaxRecoverable));
    }

    /** The payable line must name the supplier, or ageing cannot work. */
    public function test_the_grn_payable_line_carries_the_supplier_subledger(): void
    {
        $grn = $this->confirmedGrn([
            ['quantity_received' => 1, 'unit_price' => 60000, 'tax' => 0, 'line_total' => 60000],
        ], total: 60000);

        app(JournalPoster::class)->post(GoodsReceivedNoteJournal::build($grn));

        $line = $this->journalFor(PostingRole::SOURCE_GRN, $grn->id)
            ->lines
            ->firstWhere('ledger_account_id', $this->mapped(AccountRole::AccountsPayable));

        $this->assertNotNull($line);
        $this->assertSame(PostingRole::PARTY_SUPPLIER, $line->party_type);
        $this->assertSame($this->supplier->id, (int) $line->party_id);
        $this->assertSame(PostingRole::SOURCE_GRN, $line->source_type);
        $this->assertSame($grn->id, (int) $line->source_id);
    }

    // ── Invoice: the output tax side, posted by the status transition ────────

    /**
     * Issuing is what posts, not creating. 100,000 of goods at 18% plus 2,000
     * transport: revenue 102,000, output tax 18,000, receivable 120,000.
     */
    public function test_issuing_an_invoice_splits_output_tax_out_of_revenue(): void
    {
        $invoice = $this->draftInvoice(
            items: [['quantity' => 1, 'unit_price' => 100000, 'tax' => 18, 'line_total' => 118000]],
            subtotal: 118000,
            transport: 2000,
            grandTotal: 120000,
        );

        // A draft invoice is a working document, not a claim on anyone.
        $this->assertDatabaseCount('fin_journal_entries', 0);

        $this->issue($invoice);

        $this->assertSame(120000.0, $this->balance(AccountRole::AccountsReceivable));
        $this->assertSame(-102000.0, $this->balance(AccountRole::SalesRevenue), 'Goods net of tax, plus transport.');
        $this->assertSame(-18000.0, $this->balance(AccountRole::OutputTaxPayable), 'Owed to the authority, not earned.');

        $this->assertTrue(app(TrialBalanceService::class)->build()['totals']['balanced']);
    }

    /**
     * A trade discount never reaches the ledger.
     *
     * inv_invoice_items.discount is a price reduction already inside
     * line_total, so revenue is recognised net of it. Giving it a line of its
     * own would overstate both revenue and expenses by the same amount --
     * balancing, and wrong.
     */
    public function test_a_line_level_trade_discount_gets_no_ledger_line(): void
    {
        // 10,000 list, 10% off, no tax => 9,000 billed and 9,000 earned.
        $invoice = $this->draftInvoice(
            items: [['quantity' => 1, 'unit_price' => 10000, 'discount' => 10, 'tax' => 0, 'line_total' => 9000]],
            subtotal: 9000,
            transport: 0,
            grandTotal: 9000,
        );

        $this->issue($invoice);

        $journal = $this->journalFor(PostingRole::SOURCE_INVOICE, $invoice->id);

        $this->assertCount(2, $journal->lines, 'Dr Receivable, Cr Revenue. No discount line.');
        $this->assertSame(-9000.0, $this->balance(AccountRole::SalesRevenue));
        $this->assertSame(0.0, $this->balance(AccountRole::DiscountAllowed), 'That account is for SETTLEMENT discounts only.');
    }

    /** Cancelling an issued invoice must take the receivable back off. */
    public function test_cancelling_an_issued_invoice_reverses_its_journal(): void
    {
        $invoice = $this->draftInvoice(
            items: [['quantity' => 1, 'unit_price' => 40000, 'tax' => 0, 'line_total' => 40000]],
            subtotal: 40000,
            transport: 0,
            grandTotal: 40000,
        );

        $this->issue($invoice);
        $this->assertSame(40000.0, $this->balance(AccountRole::AccountsReceivable));

        $this->actingAs($this->user)
            ->patchJson("/api/v1/invoices/{$invoice->id}/status", ['status' => 'cancelled'])
            ->assertOk();

        $this->assertSame(0.0, $this->balance(AccountRole::AccountsReceivable), 'Nothing is owed on a cancelled invoice.');
        $this->assertSame(0.0, $this->balance(AccountRole::SalesRevenue), 'And nothing was earned.');

        // Two journals, not one edited: the original and its mirror.
        $this->assertDatabaseCount('fin_journal_entries', 2);
        $this->assertTrue(app(TrialBalanceService::class)->build()['totals']['balanced']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @param list<array<string, mixed>> $items */
    private function confirmedGrn(array $items, float $total): GoodsReceivedNote
    {
        static $seq = 0;
        $seq++;

        $grn = GoodsReceivedNote::create([
            'grn_no'       => sprintf('GRN-TST-%04d', $seq),
            'supplier_id'  => $this->supplier->id,
            'grn_date'     => '2026-04-05',
            'status'       => 'confirmed',
            'total_amount' => $total,
        ]);

        foreach ($items as $item) {
            GoodsReceivedNoteItem::create(array_merge([
                'grn_id'     => $grn->id,
                'product_id' => 1, // soft link; no product row needed to post
                'discount'   => 0,
            ], $item));
        }

        return $grn->fresh('items');
    }

    /** @param list<array<string, mixed>> $items */
    private function draftInvoice(array $items, float $subtotal, float $transport, float $grandTotal): Invoice
    {
        static $seq = 0;
        $seq++;

        $invoice = Invoice::create([
            'invoice_no'       => sprintf('INV-TST-%04d', $seq),
            'so_id'            => $seq, // soft link, no FK
            'customer_id'      => $this->customer->id,
            'invoice_date'     => '2026-07-10',
            'due_date'         => '2026-08-10',
            'status'           => 'draft',
            'invoice_type'     => 'tax',
            'subtotal'         => $subtotal,
            'transport_charge' => $transport,
            'grand_total'      => $grandTotal,
        ]);

        foreach ($items as $item) {
            InvoiceItem::create(array_merge([
                'invoice_id' => $invoice->id,
                'so_item_id' => $seq, // NOT NULL, but a soft link: any id works
                'product_id' => 1,
                'discount'   => 0,
            ], $item));
        }

        return $invoice->fresh('items');
    }

    /** Draft -> Issued through the API, so the posting hook is what runs. */
    private function issue(Invoice $invoice): void
    {
        $this->actingAs($this->user)
            ->patchJson("/api/v1/invoices/{$invoice->id}/status", ['status' => 'issued'])
            ->assertOk();
    }

    private function journalFor(string $sourceType, int $sourceId): JournalEntry
    {
        return JournalEntry::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->with('lines')
            ->firstOrFail();
    }

    private function mapped(AccountRole $role): int
    {
        return (int) AccountDefault::where('rule_key', $role->value)->value('ledger_account_id');
    }

    /** Debits minus credits for a role's mapped account, across the ledger. */
    private function balance(AccountRole $role): float
    {
        $lines = JournalLine::inLedger()
            ->where('ledger_account_id', $this->mapped($role))
            ->get(['debit', 'credit']);

        return round($lines->sum(fn ($l): float => (float) $l->debit - (float) $l->credit), 2);
    }
}
