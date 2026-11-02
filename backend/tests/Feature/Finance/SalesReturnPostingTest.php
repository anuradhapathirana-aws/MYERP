<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Contracts\JournalPoster;
use App\Models\Tax;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\Journal\PostingRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Finance\Database\Seeders\FinanceChartOfAccountsSeeder;
use Modules\Finance\Database\Seeders\FinanceGlAccountsSeeder;
use Modules\Finance\Enums\AccountRole;
use Modules\Finance\Models\AccountDefault;
use Modules\Finance\Models\JournalEntry;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Services\JournalPostingService;
use Modules\Finance\Services\TrialBalanceService;
use Modules\Inventory\Enums\CreditNoteStatus;
use Modules\Inventory\Enums\CustomerCreditNoteType;
use Modules\Inventory\Enums\CustomerSetoffType;
use Modules\Inventory\Models\CustomerCreditNote;
use Modules\Inventory\Models\CustomerMaster;
use Modules\Inventory\Models\CustomerReceipt;
use Modules\Inventory\Models\CustomerReceiptSetoff;
use Modules\Inventory\Models\CustomerReturn;
use Modules\Inventory\Models\CustomerReturnItem;
use Modules\Inventory\Models\Invoice;
use Modules\Inventory\Models\InvoiceItem;
use Modules\Inventory\Posting\CustomerReceiptJournal;
use Modules\Inventory\Posting\CustomerReturnJournal;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The sales side beyond the plain tax invoice: VAT inside a Non-Tax price,
 * SSCL inside every price, and the customer return that runs an invoice's
 * posting backwards.
 *
 * Each of these balances whichever way it is posted -- VAT left in Sales, a
 * return that never reaches the ledger -- so the assertions are on WHERE the
 * money lands, not merely that the Trial Balance agrees.
 */
class SalesReturnPostingTest extends TestCase
{
    use RefreshDatabase;

    private User           $user;
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

        $this->customer = CustomerMaster::create([
            'customer_code' => 'CUS-0001',
            'customer_name' => 'Nimal Stores',
            'customer_type' => 'Retail',
        ]);

        $this->seed(FinanceChartOfAccountsSeeder::class);
        $this->seed(FinanceGlAccountsSeeder::class);
    }

    // ── Invoice: VAT and SSCL taken out of the price ─────────────────────────

    /**
     * A Non-Tax invoice adds no VAT, but its price already holds it. 11,800 at
     * the default 18% is 10,000 of sales and 1,800 owed to the authority.
     */
    public function test_a_non_tax_invoice_splits_the_vat_inside_its_price(): void
    {
        $invoice = $this->draftInvoice('non_tax', [['unit_price' => 11800, 'tax' => 0, 'line_total' => 11800]], 11800);

        $this->issue($invoice);

        $this->assertSame(11800.0, $this->balance(AccountRole::AccountsReceivable));
        $this->assertSame(-10000.0, $this->balance(AccountRole::SalesRevenue));
        $this->assertSame(-1800.0, $this->balance(AccountRole::OutputTaxPayable));
        $this->assertSame(18.0, (float) InvoiceItem::firstWhere('invoice_id', $invoice->id)->vat_inclusive_pct);
    }

    /** A Tax invoice already shows its VAT on top; nothing is stamped as inclusive. */
    public function test_a_tax_invoice_stamps_no_inclusive_vat(): void
    {
        $invoice = $this->draftInvoice('tax', [['unit_price' => 100000, 'tax' => 18, 'line_total' => 118000]], 118000);

        $this->issue($invoice);

        $this->assertNull(InvoiceItem::firstWhere('invoice_id', $invoice->id)->vat_inclusive_pct);
        $this->assertSame(-18000.0, $this->balance(AccountRole::OutputTaxPayable));
    }

    /**
     * SSCL sits inside the before-VAT price the way the costing builds it:
     * 100,000 + 1.25% = 101,250, then 18% VAT on top = 119,475.
     */
    public function test_sscl_is_split_out_of_sales_when_a_rate_is_set(): void
    {
        $this->setSscl(1.25);

        $invoice = $this->draftInvoice('tax', [['unit_price' => 101250, 'tax' => 18, 'line_total' => 119475]], 119475);

        $this->issue($invoice);

        $this->assertSame(-100000.0, $this->balance(AccountRole::SalesRevenue));
        $this->assertSame(-1250.0, $this->balance(AccountRole::SsclPayable));
        $this->assertSame(-18225.0, $this->balance(AccountRole::OutputTaxPayable));
        $this->assertTrue(app(TrialBalanceService::class)->build()['totals']['balanced']);
    }

    public function test_no_sscl_line_without_a_rate(): void
    {
        $invoice = $this->draftInvoice('tax', [['unit_price' => 100000, 'tax' => 18, 'line_total' => 118000]], 118000);

        $this->issue($invoice);

        $this->assertCount(3, $this->journalFor(PostingRole::SOURCE_INVOICE, $invoice->id)->lines);
        $this->assertSame(0.0, $this->balance(AccountRole::SsclPayable));
    }

    // ── Customer return ──────────────────────────────────────────────────────

    /**
     * Half of an unpaid 118,000 tax invoice comes back. The 59,000 comes off
     * what the invoice owes, so it clears the receivable -- against the
     * invoice, so ageing follows -- and Sales Returns / Output Tax take 50,000
     * and 9,000.
     */
    public function test_a_return_on_an_unpaid_invoice_reverses_sales_and_tax_off_the_receivable(): void
    {
        $invoice = $this->draftInvoice('tax', [['unit_price' => 100000, 'tax' => 18, 'line_total' => 118000]], 118000);
        $this->issue($invoice);

        $return = $this->confirmedReturn($invoice, lineTotal: 59000, applied: 59000);
        app(JournalPoster::class)->post(CustomerReturnJournal::build($return));

        $this->assertSame(50000.0, $this->balance(AccountRole::SalesReturns));
        $this->assertSame(-9000.0, $this->balance(AccountRole::OutputTaxPayable), '18,000 collected, 9,000 given back.');
        $this->assertSame(59000.0, $this->balance(AccountRole::AccountsReceivable), 'Half the invoice is still owed.');

        $arLine = $this->journalFor(PostingRole::SOURCE_CUSTOMER_RETURN, $return->id)
            ->lines
            ->firstWhere('ledger_account_id', $this->mapped(AccountRole::AccountsReceivable));

        $this->assertSame(PostingRole::SOURCE_INVOICE, $arLine->source_type);
        $this->assertSame($invoice->id, (int) $arLine->source_id);
        $this->assertSame($this->customer->id, (int) $arLine->party_id);
        $this->assertTrue(app(TrialBalanceService::class)->build()['totals']['balanced']);
    }

    /** Already paid: nothing is owed to take it off, so the customer holds a credit. */
    public function test_a_return_on_a_paid_invoice_credits_customer_advances(): void
    {
        $invoice = $this->draftInvoice('tax', [['unit_price' => 100000, 'tax' => 18, 'line_total' => 118000]], 118000);
        $this->issue($invoice);

        $return = $this->confirmedReturn($invoice, lineTotal: 59000, applied: 0);
        app(JournalPoster::class)->post(CustomerReturnJournal::build($return));

        $this->assertSame(-59000.0, $this->balance(AccountRole::CustomerAdvance));
        $this->assertSame(118000.0, $this->balance(AccountRole::AccountsReceivable), 'The receivable is cleared by receipts, not here.');
    }

    /**
     * Rates are reversed as they were charged. The invoice went out with 1.25%
     * SSCL; switching SSCL off afterwards must not leave that SSCL behind.
     */
    public function test_a_return_reverses_at_the_rates_stamped_on_the_invoice(): void
    {
        $this->setSscl(1.25);

        $invoice = $this->draftInvoice('non_tax', [['unit_price' => 119475, 'tax' => 0, 'line_total' => 119475]], 119475);
        $this->issue($invoice);

        $this->setSscl(0);

        $return = $this->confirmedReturn($invoice, lineTotal: 119475, applied: 119475, tax: 0);
        app(JournalPoster::class)->post(CustomerReturnJournal::build($return));

        $this->assertSame(0.0, $this->balance(AccountRole::SsclPayable));
        $this->assertSame(0.0, $this->balance(AccountRole::OutputTaxPayable));
        $this->assertSame(0.0, $this->balance(AccountRole::AccountsReceivable));
        $this->assertSame(
            0.0,
            round($this->balance(AccountRole::SalesRevenue) + $this->balance(AccountRole::SalesReturns), 2),
            'Everything sold came back.',
        );
    }

    // ── Spending a return credit note on a receipt ───────────────────────────

    /**
     * The return already reversed the sale and parked the credit in Customer
     * Advances, so spending the note releases Customer Advances. Reversing
     * Sales again would count the return twice.
     */
    public function test_spending_a_posted_return_note_releases_customer_advances(): void
    {
        $invoice = $this->draftInvoice('tax', [['unit_price' => 100000, 'tax' => 18, 'line_total' => 118000]], 118000);
        $this->issue($invoice);

        $return = $this->confirmedReturn($invoice, lineTotal: 59000, applied: 0);
        app(JournalPoster::class)->post(CustomerReturnJournal::build($return));

        $note = $this->returnNote($return, 59000);

        $request = CustomerReceiptJournal::build($this->receiptSettingOff($note, 59000, $invoice));
        $setoff  = collect($request->lines)->firstWhere('description', 'Set-off');

        $this->assertSame(PostingRole::CUSTOMER_ADVANCE, $setoff->role);
    }

    /** A note from a return that never posted keeps the old treatment. */
    public function test_spending_an_unposted_return_note_still_reverses_sales(): void
    {
        $invoice = $this->draftInvoice('tax', [['unit_price' => 100000, 'tax' => 18, 'line_total' => 118000]], 118000);
        $this->issue($invoice);

        $return = $this->confirmedReturn($invoice, lineTotal: 59000, applied: 0);
        $note   = $this->returnNote($return, 59000);

        $request = CustomerReceiptJournal::build($this->receiptSettingOff($note, 59000, $invoice));
        $setoff  = collect($request->lines)->firstWhere('description', 'Set-off');

        $this->assertSame(PostingRole::SALES_REVENUE, $setoff->role);
    }

    // ── Existing installations ───────────────────────────────────────────────

    /** The migration gives a live chart the new accounts, and touches nothing else. */
    public function test_the_migration_provisions_only_the_new_roles(): void
    {
        $newLedgers = [$this->mapped(AccountRole::SalesReturns), $this->mapped(AccountRole::SsclPayable)];
        AccountDefault::whereIn('rule_key', [AccountRole::SalesReturns->value, AccountRole::SsclPayable->value])->delete();
        LedgerAccount::whereKey($newLedgers)->forceDelete();

        AccountDefault::firstWhere('rule_key', AccountRole::Purchases->value)->delete();
        $before = LedgerAccount::pluck('ledger_account_name')->all();

        (require base_path('Modules/Finance/database/migrations/2026_11_01_000001_provision_sales_returns_and_sscl_accounts.php'))->up();

        $this->assertEqualsCanonicalizing(
            ['Sales Returns', 'SSCL Payable'],
            array_values(array_diff(LedgerAccount::pluck('ledger_account_name')->all(), $before)),
        );
        $ledgerCount = count($before);

        $this->assertNotSame(0, $this->mapped(AccountRole::SalesReturns));
        $this->assertNotSame(0, $this->mapped(AccountRole::SsclPayable));
        $this->assertSame($ledgerCount + 2, LedgerAccount::count(), 'Only the two new accounts were created.');
        $this->assertNull(AccountDefault::firstWhere('rule_key', AccountRole::Purchases->value), 'Other roles are left alone.');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @param list<array<string, mixed>> $items */
    /** Master Data > Taxes: set the SSCL rate; 0 switches SSCL off. */
    private function setSscl(float $rate): void
    {
        Tax::where('code', Tax::SSCL)->update(['rate' => $rate > 0 ? $rate : 1.25, 'is_active' => $rate > 0]);
        Cache::forget('core_taxes.rows');
    }

    private function draftInvoice(string $type, array $items, float $grandTotal): Invoice
    {
        static $seq = 0;
        $seq++;

        $invoice = Invoice::create([
            'invoice_no'       => sprintf('INV-SR-%04d', $seq),
            'so_id'            => $seq,
            'customer_id'      => $this->customer->id,
            'invoice_date'     => '2026-07-10',
            'due_date'         => '2026-08-10',
            'status'           => 'draft',
            'invoice_type'     => $type,
            'subtotal'         => $grandTotal,
            'transport_charge' => 0,
            'grand_total'      => $grandTotal,
        ]);

        foreach ($items as $item) {
            InvoiceItem::create(array_merge([
                'invoice_id' => $invoice->id,
                'so_item_id' => $seq,
                'product_id' => 1,
                'quantity'   => 1,
                'discount'   => 0,
            ], $item));
        }

        return $invoice->fresh('items');
    }

    private function issue(Invoice $invoice): void
    {
        $this->actingAs($this->user)
            ->patchJson("/api/v1/invoices/{$invoice->id}/status", ['status' => 'issued'])
            ->assertOk();
    }

    /** A confirmed return of one line, as CustomerReturnService leaves it. */
    private function confirmedReturn(Invoice $invoice, float $lineTotal, float $applied, float $tax = 18): CustomerReturn
    {
        static $seq = 0;
        $seq++;

        $return = CustomerReturn::create([
            'return_no'          => sprintf('RET-TST-%04d', $seq),
            'return_date'        => '2026-07-20',
            'customer_id'        => $this->customer->id,
            'invoice_id'         => $invoice->id,
            'store_id'           => 1,
            'location_id'        => 1,
            'status'             => 'confirmed',
            'total_amount'       => $lineTotal,
            'applied_to_invoice' => $applied,
        ]);

        CustomerReturnItem::create([
            'return_id'       => $return->id,
            'invoice_item_id' => $invoice->items->first()->id,
            'product_id'      => 1,
            'quantity'        => 1,
            'unit_price'      => $lineTotal,
            'discount'        => 0,
            'tax'             => $tax,
            'line_total'      => $lineTotal,
            'store_id'        => 1,
            'location_id'     => 1,
            'condition'       => 'good',
            'reason'          => 'damaged',
        ]);

        return $return->fresh(['items', 'customer', 'invoice']);
    }

    private function returnNote(CustomerReturn $return, float $amount): CustomerCreditNote
    {
        return CustomerCreditNote::create([
            'credit_note_no'    => 'CN-' . $return->id,
            'customer_id'       => $this->customer->id,
            'credit_type'       => CustomerCreditNoteType::SalesReturn,
            'amount'            => $amount,
            'remaining_balance' => $amount,
            'status'            => CreditNoteStatus::Open,
            'source_return_id'  => $return->id,
        ]);
    }

    /** An unsaved receipt that only sets a credit note off -- enough to build its journal. */
    private function receiptSettingOff(CustomerCreditNote $note, float $amount, Invoice $invoice): CustomerReceipt
    {
        $receipt = new CustomerReceipt([
            'receipt_no'      => 'RCP-TST',
            'receipt_date'    => '2026-07-25',
            'customer_id'     => $this->customer->id,
            'discount_amount' => 0,
        ]);
        $receipt->id = 999;

        $receipt->setRelation('settlements', collect());
        $receipt->setRelation('customer', $this->customer);
        $receipt->setRelation('setoffs', collect([new CustomerReceiptSetoff([
            'setoff_type'    => CustomerSetoffType::SalesReturn,
            'credit_note_id' => $note->id,
            'amount'         => $amount,
        ])]));
        $receipt->setRelation('allocations', collect([(object) [
            'receipt_amount' => $amount,
            'discount'       => 0,
            'so_no'          => null,
            'reference_type' => PostingRole::SOURCE_INVOICE,
            'reference_id'   => $invoice->id,
        ]]));

        return $receipt;
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
