<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\PaymentMode;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\Journal\PostingRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Database\Seeders\FinanceChartOfAccountsSeeder;
use Modules\Finance\Database\Seeders\FinanceGlAccountsSeeder;
use Modules\Finance\DTOs\LedgerAccountData;
use Modules\Finance\Enums\AccountRole;
use Modules\Finance\Enums\CashBookType;
use Modules\Finance\Enums\JournalStatus;
use Modules\Finance\Models\AccountDefault;
use Modules\Finance\Models\ControlAccount;
use Modules\Finance\Models\JournalEntry;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Services\JournalPostingService;
use Modules\Finance\Services\LedgerAccountService;
use Modules\Finance\Services\TrialBalanceService;
use Modules\Inventory\Models\CustomerMaster;
use Modules\Inventory\Models\CustomerReceipt;
use Modules\Inventory\Models\Invoice;
use Modules\Inventory\Models\InvoiceItem;
use Modules\Inventory\Posting\InvoiceJournal;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A customer receipt reaching the general ledger: the mirror of
 * SupplierPaymentPostingTest, with every side flipped.
 *
 * The receivable half of the system is not a copy-paste of the payable half,
 * and one difference is worth a test of its own: Customer Advances is a
 * LIABILITY, so an overpayment CREDITS it, whereas Supplier Advances is an
 * asset and an overpayment DEBITS it. Same word, opposite sign. Getting that
 * backwards balances perfectly and produces a Balance Sheet that is wrong by
 * twice the advance, which is exactly the kind of error only a test catches.
 */
class CustomerReceiptPostingTest extends TestCase
{
    use RefreshDatabase;

    private User           $user;
    private CustomerMaster $customer;
    private PaymentMode    $cheque;
    private LedgerAccount  $bankA;
    private LedgerAccount  $bankB;

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

        $permissions = [
            'view_customer_receipts', 'create_customer_receipts', 'edit_customer_receipts',
            'delete_customer_receipts', 'confirm_customer_receipts',
            // Separate from confirm on purpose: undoing something already in
            // the ledger is a different level of trust from entering it.
            'void_customer_receipts',
        ];

        foreach ($permissions as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['inventory', 'finance']]);
        $this->user->givePermissionTo($permissions);

        $this->customer = CustomerMaster::create([
            'customer_code' => 'CUS-0001',
            'customer_name' => 'Nimal Stores',
            'customer_type' => 'Retail',
        ]);

        $this->cheque = PaymentMode::create([
            'payment_mode_name' => 'Cheque', 'code' => 'cheque', 'is_active' => true,
        ]);

        $this->seed(FinanceChartOfAccountsSeeder::class);
        $this->seed(FinanceGlAccountsSeeder::class);

        // Two accounts at the same company -- the scenario bank_id alone cannot
        // express, and the reason the settlement line needed its own column.
        $this->bankA = $this->bankAccount('Sampath Bank - Current', companyId: 1);
        $this->bankB = $this->bankAccount('Commercial Bank - Savings', companyId: 1);
    }

    // ── The worked case ─────────────────────────────────────────────────────

    /**
     * 100,000 invoiced. 5,000 forgiven as a settlement discount, 95,000
     * received into two different bank accounts of the same company.
     */
    public function test_a_receipt_with_a_discount_and_two_banks_posts_correctly(): void
    {
        $invoice = $this->issuedInvoice(100000);

        $receipt = $this->confirmReceipt([
            'allocations' => [[
                'reference_type' => 'invoice',
                'reference_id'   => $invoice->id,
                'receipt_amount' => 95000,
                'discount'       => 5000,
            ]],
            'settlements' => [
                $this->settlement($this->bankA, 60000, '123456'),
                $this->settlement($this->bankB, 35000, '654321'),
            ],
        ]);

        $journal = JournalEntry::where('source_type', PostingRole::SOURCE_CUSTOMER_RECEIPT)
            ->where('source_id', $receipt->id)
            ->with('lines')
            ->firstOrFail();

        // Dr bank A 60,000 + Dr bank B 35,000 + Dr discount 5,000
        //   = Cr receivable 100,000
        $this->assertSame(100000.0, round((float) $journal->total_debit, 2));
        $this->assertSame(100000.0, round((float) $journal->total_credit, 2));
        $this->assertCount(4, $journal->lines);

        $this->assertSame(60000.0, $this->lineDebit($journal, $this->bankA->id));
        $this->assertSame(35000.0, $this->lineDebit($journal, $this->bankB->id));
        $this->assertSame(5000.0, $this->lineDebit($journal, $this->mapped(AccountRole::DiscountAllowed)));

        // The receivable line carries the subledger: who owes, and which
        // document is being cleared. This is what Aged Receivables reads.
        $receivable = $journal->lines
            ->firstWhere('ledger_account_id', $this->mapped(AccountRole::AccountsReceivable));

        $this->assertNotNull($receivable);
        $this->assertSame(100000.0, round((float) $receivable->credit, 2));
        $this->assertSame(PostingRole::PARTY_CUSTOMER, $receivable->party_type);
        $this->assertSame($this->customer->id, (int) $receivable->party_id);
        $this->assertSame('invoice', $receivable->source_type);
        $this->assertSame($invoice->id, (int) $receivable->source_id);
    }

    // ── The bridge ──────────────────────────────────────────────────────────

    /**
     * An invoice and the receipt that settles it must leave Receivables at zero.
     *
     * The invoice debits Receivables, the receipt credits it, and only together
     * do they square. Post the receipt alone and the account reads as a
     * negative asset.
     */
    public function test_an_invoice_and_its_receipt_leave_receivables_square(): void
    {
        $invoice = $this->issuedInvoice(100000, withItem: true);

        // Post the invoice side the way updateStatus() now does.
        app(\App\Contracts\JournalPoster::class)->post(InvoiceJournal::build($invoice));

        $receivables = $this->mapped(AccountRole::AccountsReceivable);

        $this->assertSame(
            100000.0,
            $this->balance($receivables),
            'After the invoice alone they owe us 100,000 -- a debit balance on an asset.',
        );

        $this->confirmReceipt([
            'allocations' => [['reference_type' => 'invoice', 'reference_id' => $invoice->id, 'receipt_amount' => 100000, 'discount' => 0]],
            'settlements' => [$this->settlement($this->bankA, 100000, '999999')],
        ]);

        $this->assertSame(0.0, $this->balance($receivables), 'Receiving in full must clear the asset exactly.');

        $trial = app(TrialBalanceService::class)->build();
        $this->assertTrue($trial['totals']['balanced']);
        $this->assertSame($trial['totals']['debit'], $trial['totals']['credit']);
    }

    // ── The sign trap ───────────────────────────────────────────────────────

    /**
     * An advance receipt CREDITS Customer Advances, because it is a liability.
     *
     * Money held for a customer who has not yet bought anything is owed back to
     * them. Were this debited -- the way Supplier Advances correctly is -- the
     * journal would still balance and the Balance Sheet would be wrong by
     * 2 x the advance, with nothing anywhere to flag it.
     */
    public function test_an_advance_receipt_credits_customer_advances_as_a_liability(): void
    {
        $receipt = $this->confirmReceipt([
            'is_advance'     => true,
            'advance_amount' => 25000,
            'allocations'    => [],
            'settlements'    => [$this->settlement($this->bankA, 25000, '222222')],
        ]);

        $advances = $this->mapped(AccountRole::CustomerAdvance);

        $this->assertSame(
            -25000.0,
            $this->balance($advances),
            'A credit balance, i.e. negative under debit-minus-credit: we owe them this.',
        );

        // And the money really is in the bank.
        $this->assertSame(25000.0, $this->balance($this->bankA->id));

        $journal = JournalEntry::where('source_id', $receipt->id)
            ->where('source_type', PostingRole::SOURCE_CUSTOMER_RECEIPT)
            ->with('lines')
            ->firstOrFail();

        $advanceLine = $journal->lines->firstWhere('ledger_account_id', $advances);
        $this->assertNotNull($advanceLine);
        $this->assertSame(0.0, round((float) $advanceLine->debit, 2), 'An advance must never DEBIT this account.');
        $this->assertSame($this->customer->id, (int) $advanceLine->party_id);
    }

    // ── Idempotency and independence ────────────────────────────────────────

    public function test_confirming_is_the_only_thing_that_posts_and_it_posts_once(): void
    {
        $invoice = $this->issuedInvoice(10000);

        $id = $this->createReceipt([
            'allocations' => [['reference_type' => 'invoice', 'reference_id' => $invoice->id, 'receipt_amount' => 10000, 'discount' => 0]],
            'settlements' => [$this->settlement($this->bankA, 10000, '333333')],
        ]);

        // A draft receipt is a working document: nothing in the ledger yet.
        $this->assertDatabaseCount('fin_journal_entries', 0);

        $this->actingAs($this->user)->postJson("/api/v1/customer-receipts/{$id}/confirm")->assertOk();
        $this->assertDatabaseCount('fin_journal_entries', 1);

        // A second confirm is refused by the document, and the unique index on
        // (source_type, source_id) is the backstop if it ever were not.
        $this->actingAs($this->user)->postJson("/api/v1/customer-receipts/{$id}/confirm")->assertStatus(422);
        $this->assertDatabaseCount('fin_journal_entries', 1);
    }

    /**
     * An Inventory-only client. The receipt must confirm exactly as it did
     * before Finance existed, and nothing may reach the ledger.
     */
    public function test_a_receipt_confirms_normally_with_finance_disabled(): void
    {
        app(SettingsService::class)->set('module.finance', false);

        $invoice = $this->issuedInvoice(10000);

        $receipt = $this->confirmReceipt([
            'allocations' => [['reference_type' => 'invoice', 'reference_id' => $invoice->id, 'receipt_amount' => 10000, 'discount' => 0]],
            // No Deposit To account either, because such a client has no chart
            // of accounts to choose from. It must still confirm.
            'settlements' => [[
                'payment_mode_id' => $this->cheque->id,
                'amount'          => 10000,
                'reference_no'    => '888888',
                'instrument_date' => '2026-07-20',
            ]],
        ]);

        $this->assertSame('confirmed', $receipt->status->value);
        $this->assertDatabaseCount('fin_journal_entries', 0);
    }

    /**
     * With Finance ON, a settlement with no Deposit To account must fail loudly
     * and roll the whole confirm back -- never post to a guessed account.
     */
    public function test_a_settlement_without_a_deposit_account_is_refused(): void
    {
        $invoice = $this->issuedInvoice(10000);

        $id = $this->createReceipt([
            'allocations' => [['reference_type' => 'invoice', 'reference_id' => $invoice->id, 'receipt_amount' => 10000, 'discount' => 0]],
            'settlements' => [[
                'payment_mode_id' => $this->cheque->id,
                'amount'          => 10000,
                'reference_no'    => '777777',
                'instrument_date' => '2026-07-20',
            ]],
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/customer-receipts/{$id}/confirm")
            ->assertStatus(422)
            ->assertJsonValidationErrors('settlements');

        $this->assertDatabaseCount('fin_journal_entries', 0);
        $this->assertSame('draft', CustomerReceipt::findOrFail($id)->status->value);
    }

    // ── Void ────────────────────────────────────────────────────────────────

    public function test_voiding_a_receipt_reverses_the_journal_and_reopens_the_invoice(): void
    {
        $invoice = $this->issuedInvoice(100000, withItem: true);

        app(\App\Contracts\JournalPoster::class)->post(InvoiceJournal::build($invoice));

        $receipt = $this->confirmReceipt([
            'allocations' => [['reference_type' => 'invoice', 'reference_id' => $invoice->id, 'receipt_amount' => 100000, 'discount' => 0]],
            'settlements' => [$this->settlement($this->bankA, 100000, '101010')],
        ]);

        $receivables = $this->mapped(AccountRole::AccountsReceivable);
        $this->assertSame(0.0, $this->balance($receivables));
        $this->assertSame('paid', $invoice->fresh()->status->value, 'Settled in full, so the invoice is Paid.');

        $this->actingAs($this->user)
            ->postJson("/api/v1/customer-receipts/{$receipt->id}/void", ['reason' => 'Cheque returned unpaid'])
            ->assertOk()
            ->assertJsonPath('data.status', 'voided');

        // The receipt journal is reversed, so the debt is owed again -- and the
        // money is no longer in the bank.
        $this->assertSame(100000.0, $this->balance($receivables), 'Voiding must restore the receivable.');
        $this->assertSame(0.0, $this->balance($this->bankA->id), 'And take the money back out.');

        // The invoice is collectable again.
        $this->assertSame('issued', $invoice->fresh()->status->value);
        $this->assertNull($invoice->fresh()->paid_at);

        $trial = app(TrialBalanceService::class)->build();
        $this->assertTrue($trial['totals']['balanced'], 'Original and reversal both count, so it still balances.');
    }

    public function test_a_voided_receipt_cannot_be_voided_again(): void
    {
        $receipt = $this->simpleReceipt(10000, '121212');

        $this->actingAs($this->user)->postJson("/api/v1/customer-receipts/{$receipt->id}/void")->assertOk();
        $this->actingAs($this->user)->postJson("/api/v1/customer-receipts/{$receipt->id}/void")->assertStatus(422);
    }

    public function test_a_voided_receipt_can_never_be_deleted(): void
    {
        $receipt = $this->simpleReceipt(10000, '131313');

        $this->actingAs($this->user)->postJson("/api/v1/customer-receipts/{$receipt->id}/void")->assertOk();

        // The document stays exactly as it was. Erasing it would erase the
        // record that the mistake happened.
        $this->actingAs($this->user)->deleteJson("/api/v1/customer-receipts/{$receipt->id}")->assertStatus(422);
        $this->assertDatabaseHas('inv_customer_receipts', ['id' => $receipt->id, 'deleted_at' => null]);
    }

    // -- Review mode: the draft journal, and what void must do to it --------

    /**
     * Voiding a receipt whose journal is still a DRAFT must discard the draft.
     *
     * In Review mode a confirm creates a draft for finance to check. If the
     * document is then voided, that draft describes a transaction that never
     * happened -- and it used to be left sitting in the Journal Vouchers list
     * with a live Post button, so the voided receipt could still be posted.
     */
    public function test_voiding_a_receipt_discards_its_unposted_draft_journal(): void
    {
        app(SettingsService::class)->set(
            JournalPostingService::SETTING_POSTING_MODE,
            JournalPostingService::MODE_REVIEW,
        );

        $receipt = $this->simpleReceipt(10000, '454545');

        // Review mode: in the list, not yet in the books.
        $this->assertDatabaseCount('fin_journal_entries', 1);
        $this->assertSame(
            JournalStatus::Draft,
            JournalEntry::firstOrFail()->status,
            'Review mode must not post on confirm.',
        );
        $this->assertSame(0.0, $this->balance($this->bankA->id), 'A draft is not in the ledger.');

        $this->actingAs($this->user)
            ->postJson("/api/v1/customer-receipts/{$receipt->id}/void", ['reason' => 'Entered twice'])
            ->assertOk();

        // Gone completely -- not soft-deleted. A shell row would still occupy
        // the unique (source_type, source_id) slot and block a re-post.
        $this->assertSame(
            0,
            JournalEntry::withTrashed()->count(),
            'The draft must be removed outright, leaving no shell behind.',
        );

        // And no reversal was invented for something never posted.
        $this->assertDatabaseCount('fin_journal_lines', 0);
    }

    /** Deleting a draft must leave nothing behind, so its document can post again. */
    public function test_deleting_a_draft_journal_removes_it_outright(): void
    {
        app(SettingsService::class)->set(
            JournalPostingService::SETTING_POSTING_MODE,
            JournalPostingService::MODE_REVIEW,
        );

        Permission::findOrCreate('delete_journal_entries', 'web');
        $this->user->givePermissionTo('delete_journal_entries');

        $this->simpleReceipt(10000, '565656');

        $draft = JournalEntry::firstOrFail();
        $this->assertSame(JournalStatus::Draft, $draft->status);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/journal-entries/{$draft->id}")
            ->assertNoContent();

        $this->assertSame(0, JournalEntry::withTrashed()->count());
        $this->assertDatabaseCount('fin_journal_lines', 0);

        // The second click is what produced "No query results for model
        // [JournalEntry] 6". It is now an honest 404 on a row that is really
        // gone, rather than one hidden behind a soft delete.
        $this->actingAs($this->user)
            ->deleteJson("/api/v1/journal-entries/{$draft->id}")
            ->assertStatus(404);
    }

    /** A posted journal is still untouchable by delete. */
    public function test_a_posted_journal_can_never_be_deleted(): void
    {
        Permission::findOrCreate('delete_journal_entries', 'web');
        $this->user->givePermissionTo('delete_journal_entries');

        $this->simpleReceipt(10000, '676767');

        $posted = JournalEntry::firstOrFail();
        $this->assertSame(JournalStatus::Posted, $posted->status);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/journal-entries/{$posted->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('fin_journal_entries', ['id' => $posted->id, 'deleted_at' => null]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * An issued invoice, created directly.
     *
     * withItem adds the one line InvoiceJournal needs: with no items it would
     * compute zero revenue against a non-zero grand total and refuse to
     * balance -- correct behaviour on inconsistent data, but not what these
     * tests are about. Status is set here rather than through updateStatus() so
     * the invoice journal is posted explicitly where a test wants it.
     */
    private function issuedInvoice(float $grandTotal, bool $withItem = false): Invoice
    {
        static $seq = 0;
        $seq++;

        $invoice = Invoice::create([
            'invoice_no'   => sprintf('INV-TST-%04d', $seq),
            'so_id'        => $seq, // soft link, no FK
            'customer_id'  => $this->customer->id,
            'invoice_date' => '2026-07-10',
            'due_date'     => '2026-08-10',
            'status'       => 'issued',
            'invoice_type' => 'non_tax',
            'subtotal'     => $grandTotal,
            'grand_total'  => $grandTotal,
        ]);

        if ($withItem) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'so_item_id' => $seq, // NOT NULL, but a soft link: any id works
                'product_id' => 1,    // soft link; no product row needed to post
                'quantity'   => 1,
                'unit_price' => $grandTotal,
                'discount'   => 0,
                'tax'        => 0,
                'line_total' => $grandTotal,
            ]);
        }

        return $invoice->fresh('items');
    }

    /** A confirmed receipt settling one invoice in full, for the void tests. */
    private function simpleReceipt(float $amount, string $chequeNo): CustomerReceipt
    {
        $invoice = $this->issuedInvoice($amount, withItem: true);

        return $this->confirmReceipt([
            'allocations' => [['reference_type' => 'invoice', 'reference_id' => $invoice->id, 'receipt_amount' => $amount, 'discount' => 0]],
            'settlements' => [$this->settlement($this->bankA, $amount, $chequeNo)],
        ]);
    }

    private function bankAccount(string $name, int $companyId): LedgerAccount
    {
        $control = ControlAccount::where('control_account_name', 'Cash & Cash Equivalents')->firstOrFail();

        return app(LedgerAccountService::class)->create(new LedgerAccountData(
            control_account_id:  $control->id,
            ledger_account_name: $name,
            description:         null,
            is_active:           true,
            cash_book_type:      CashBookType::Bank,
            company_id:          $companyId,
            allows_cash:         false,
            allows_cheque:       true,
            bank_id:             null,
            bank_branch_id:      null,
            bank_account_no:     null,
        ));
    }

    /** @return array<string, mixed> */
    private function settlement(LedgerAccount $account, float $amount, string $chequeNo): array
    {
        return [
            'payment_mode_id'   => $this->cheque->id,
            'amount'            => $amount,
            'ledger_account_id' => $account->id,
            'reference_no'      => $chequeNo,
            'instrument_date'   => '2026-07-20',
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function createReceipt(array $overrides): int
    {
        $payload = array_merge([
            'receipt_date' => '2026-07-20',
            'customer_id'  => $this->customer->id,
            'is_advance'   => false,
            'allocations'  => [],
            'setoffs'      => [],
            'settlements'  => [],
        ], $overrides);

        return (int) $this->actingAs($this->user)
            ->postJson('/api/v1/customer-receipts', $payload)
            ->assertCreated()
            ->json('data.id');
    }

    /** @param array<string, mixed> $overrides */
    private function confirmReceipt(array $overrides): CustomerReceipt
    {
        $id = $this->createReceipt($overrides);

        $this->actingAs($this->user)
            ->postJson("/api/v1/customer-receipts/{$id}/confirm")
            ->assertOk();

        return CustomerReceipt::findOrFail($id);
    }

    private function mapped(AccountRole $role): int
    {
        return (int) AccountDefault::where('rule_key', $role->value)->value('ledger_account_id');
    }

    /** The debit on the one line hitting this account, for single-line assertions. */
    private function lineDebit(JournalEntry $journal, int $accountId): float
    {
        $line = $journal->lines->firstWhere('ledger_account_id', $accountId);

        return $line === null ? 0.0 : round((float) $line->debit, 2);
    }

    /** Debits minus credits for one account, across everything in the ledger. */
    private function balance(int $accountId): float
    {
        $lines = JournalLine::inLedger()
            ->where('ledger_account_id', $accountId)
            ->get(['debit', 'credit']);

        return round($lines->sum(fn ($l): float => (float) $l->debit - (float) $l->credit), 2);
    }
}
