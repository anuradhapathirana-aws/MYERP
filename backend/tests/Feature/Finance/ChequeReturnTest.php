<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Contracts\FundBalanceGuard;
use App\Enums\PaymentModeType;
use App\Models\PaymentMode;
use App\Services\SettingsService;
use App\Support\Journal\NullFundBalanceGuard;
use App\Support\Journal\PostingRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Finance\DTOs\LedgerAccountData;
use Modules\Finance\Enums\AccountRole;
use Modules\Finance\Enums\BankReconciliationStatus;
use Modules\Finance\Enums\CashBookType;
use Modules\Finance\Enums\PaymentStatus;
use Modules\Finance\Models\BankReconciliation;
use Modules\Finance\Models\BillPayment;
use Modules\Finance\Models\ControlAccount;
use Modules\Finance\Models\JournalEntry;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Services\LedgerAccountService;
use Modules\Inventory\Enums\CreditNoteStatus;
use Modules\Inventory\Enums\InvoiceStatus;
use Modules\Inventory\Models\ChequeReturn;
use Modules\Inventory\Models\CustomerCreditNote;
use Modules\Inventory\Models\CustomerMaster;
use Modules\Inventory\Models\CustomerReceipt;
use Modules\Inventory\Models\GoodsReceivedNote;
use Modules\Inventory\Models\Invoice;
use Modules\Inventory\Models\SupplierPayment;
use Modules\Inventory\Services\GrnBalanceService;
use Modules\Inventory\Services\InvoiceBalanceService;
use Tests\Feature\Finance\Concerns\BillFixtures;
use Tests\TestCase;

/**
 * Phase 3 of Bank Reconciliation: a line marked Returned posts the bounce
 * entry of the document that wrote the cheque and reopens what it settled.
 *
 * Received cheques (customer receipts) reopen invoices; issued cheques
 * (supplier payments, bill payments) reopen GRNs and bills. The bounce's own
 * bank line is cleared on the same reconciliation, the return is final, and
 * the customer is flagged.
 */
class ChequeReturnTest extends TestCase
{
    use BillFixtures;
    use RefreshDatabase;

    private LedgerAccount $bank;
    private LedgerAccount $chequesInHand;
    private CustomerMaster $customer;

    /** @var array<string, PaymentMode> */
    private array $modes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->billPermissions = [
            ...$this->billPermissions,
            'view_bank_reconciliations', 'create_bank_reconciliations', 'edit_bank_reconciliations',
            'delete_bank_reconciliations', 'post_bank_reconciliations', 'void_bank_reconciliations',
            'view_customer_receipts', 'create_customer_receipts', 'confirm_customer_receipts', 'void_customer_receipts',
            'view_supplier_payments', 'create_supplier_payments', 'confirm_supplier_payments', 'void_supplier_payments',
            'view_bill_payments', 'create_bill_payments', 'confirm_bill_payments', 'void_bill_payments',
            'view_bank_deposits', 'create_bank_deposits', 'confirm_bank_deposits', 'void_bank_deposits',
            'view_customer_masters',
        ];

        $this->setUpBills();

        app(SettingsService::class)->set('module.inventory', true);
        $this->user->forceFill(['active_modules' => ['inventory', 'finance']])->save();

        // Balances are pinned on unfunded accounts; the funds rule has its own tests.
        $this->app->instance(FundBalanceGuard::class, new NullFundBalanceGuard());

        $this->bank          = $this->moneyAccount('Sampath Current', CashBookType::Bank, cheque: true);
        $this->chequesInHand = $this->moneyAccount('Cheques in Hand', CashBookType::CashBook, cheque: true);

        foreach ([
            ['Cash', 'cash', PaymentModeType::Cash],
            ['Cheque', 'cheque', PaymentModeType::Cheque],
            ['Online Transfer', 'online_transfer', PaymentModeType::BankTransfer],
        ] as $i => [$name, $code, $type]) {
            $this->modes[$code] = PaymentMode::updateOrCreate(['code' => $code], [
                'payment_mode_name' => $name, 'type' => $type, 'sort_order' => $i + 1, 'is_active' => true,
            ]);
        }

        $this->customer = CustomerMaster::create([
            'customer_code' => 'CUS-0115',
            'customer_name' => 'Nimal Stores',
            'customer_type' => 'Retail',
        ]);
    }

    // ── Received cheques ─────────────────────────────────────────────────────

    public function test_a_returned_receipt_cheque_reopens_the_invoice_and_posts_the_bounce(): void
    {
        $invoice = $this->issuedInvoice(73027.17);
        $receipt = $this->receipt([[$invoice, 73027.17]], $this->settlement('cheque', 73027.17, '123456'));

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);

        $chequeLine = $this->bankLine(PostingRole::SOURCE_CUSTOMER_RECEIPT_SETTLEMENT, (int) $receipt->settlements()->value('id'));
        $recon      = $this->createRecon([$chequeLine => 'returned'], '0.00');

        $this->postRecon($recon)->assertOk();

        // The invoice is owed again.
        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
        $this->assertSame(73027.17, round(app(InvoiceBalanceService::class)->outstanding($invoice->id), 2));

        // The bounce: Dr Receivables on the invoice / Cr the bank, same cheque.
        $return = ChequeReturn::firstOrFail();
        $entry  = JournalEntry::where('source_type', PostingRole::SOURCE_CHEQUE_RETURN)->where('source_id', $return->id)->with('lines')->firstOrFail();

        $receivable = $entry->lines->firstWhere('ledger_account_id', $this->mapped(AccountRole::AccountsReceivable));
        $this->assertSame(73027.17, round((float) $receivable->debit, 2));
        $this->assertSame('invoice', $receivable->source_type);
        $this->assertSame($invoice->id, (int) $receivable->source_id);
        $this->assertSame($this->customer->id, (int) $receivable->party_id);

        $bankLine = $entry->lines->firstWhere('ledger_account_id', $this->bank->id);
        $this->assertSame(73027.17, round((float) $bankLine->credit, 2));
        $this->assertSame('123456', $bankLine->instrument_no);
        $this->assertSame(0.0, $this->balance($this->bank->id), 'The cheque and its bounce cancel out.');

        // Both bank lines are cleared on the reconciliation, so nothing is left uncleared.
        $recon->refresh()->load('lines');
        $this->assertSame(BankReconciliationStatus::Posted, $recon->status);
        $this->assertCount(2, $recon->lines);
        $this->assertSame($entry->journal_no, $recon->lines->firstWhere('journal_line_id', $chequeLine)->return_journal_no);
        $this->assertSame([], $this->workspace('2026-10-25')['lines']);
        $this->assertSame('0.00', (string) $recon->closing_balance);

        $this->assertSame($recon->recon_no, $return->reference);
        $this->assertSame($entry->journal_no, $return->journal_no);
    }

    public function test_the_return_is_final_and_flags_the_customer(): void
    {
        $invoice = $this->issuedInvoice(5000);
        $receipt = $this->receipt([[$invoice, 5000]], $this->settlement('cheque', 5000, '200001'));
        $line    = $this->bankLine(PostingRole::SOURCE_CUSTOMER_RECEIPT_SETTLEMENT, (int) $receipt->settlements()->value('id'));
        $recon   = $this->createRecon([$line => 'returned'], '0.00');

        $this->postRecon($recon)->assertOk();

        // The receipt cannot be voided: the bounce already reopened the invoice.
        $this->actingAs($this->user)
            ->postJson("/api/v1/customer-receipts/{$receipt->id}/void", ['reason' => 'Bounced'])
            ->assertStatus(422);
        $this->assertSame('confirmed', $receipt->fresh()->status->value);

        // Nor can the reconciliation be undone.
        $this->actingAs($this->user)
            ->postJson("/api/v1/bank-reconciliations/{$recon->id}/void", ['reason' => 'Entered by mistake'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        // The customer carries the flag.
        $this->actingAs($this->user)
            ->getJson("/api/v1/customer-masters/{$this->customer->id}/returned-cheques")
            ->assertOk()
            ->assertJsonPath('data.0.cheque_no', '200001')
            ->assertJsonPath('data.0.amount', '5000.00')
            ->assertJsonPath('data.0.document_no', $receipt->receipt_no)
            ->assertJsonPath('data.0.reference', $recon->recon_no);

        // Re-presenting the cheque is a new receipt, and the invoice takes it.
        // Dated after the reconciled statement: that period is closed for the bank.
        $again = $this->receipt([[$invoice, 5000]], $this->settlement('cheque', 5000, '200001'), '2026-10-21');
        $this->assertSame('confirmed', $again->status->value);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_a_cheque_that_also_funded_an_overpayment_takes_its_credit_note_back_first(): void
    {
        $invoice = $this->issuedInvoice(10000);
        $receipt = $this->receipt([[$invoice, 10000]], $this->settlement('cheque', 15000, '300001'));

        $note = CustomerCreditNote::where('source_receipt_id', $receipt->id)->firstOrFail();
        $this->assertSame('5000.0000', (string) $note->remaining_balance);

        $line = $this->bankLine(PostingRole::SOURCE_CUSTOMER_RECEIPT_SETTLEMENT, (int) $receipt->settlements()->value('id'));
        $this->postRecon($this->createRecon([$line => 'returned'], '0.00'))->assertOk();

        $note->refresh();
        $this->assertSame('0.0000', (string) $note->remaining_balance);
        $this->assertSame(CreditNoteStatus::Exhausted, $note->status);
        $this->assertSame(10000.0, round(app(InvoiceBalanceService::class)->outstanding($invoice->id), 2));

        $entry = JournalEntry::where('source_type', PostingRole::SOURCE_CHEQUE_RETURN)->with('lines')->firstOrFail();
        $this->assertSame(5000.0, round((float) $entry->lines->firstWhere('ledger_account_id', $this->mapped(AccountRole::CustomerAdvance))->debit, 2));
        $this->assertSame(10000.0, round((float) $entry->lines->firstWhere('ledger_account_id', $this->mapped(AccountRole::AccountsReceivable))->debit, 2));
    }

    public function test_a_return_is_refused_when_nothing_is_left_to_reopen(): void
    {
        $receipt = $this->advanceReceipt($this->settlement('cheque', 8000, '400001'));

        // The advance is gone, but no confirmed document spent it (data
        // corrected by hand): there is nothing to reopen, so nothing posts.
        CustomerCreditNote::where('source_receipt_id', $receipt->id)->update(['remaining_balance' => 0, 'status' => 'exhausted']);

        $line  = $this->bankLine(PostingRole::SOURCE_CUSTOMER_RECEIPT_SETTLEMENT, (int) $receipt->settlements()->value('id'));
        $recon = $this->createRecon([$line => 'returned'], '0.00');

        $this->postRecon($recon)->assertUnprocessable()->assertJsonValidationErrors('lines');

        $this->assertSame(BankReconciliationStatus::Draft, $recon->fresh()->status);
        $this->assertSame(0, ChequeReturn::count());
        $this->assertSame(0, JournalEntry::where('source_type', PostingRole::SOURCE_CHEQUE_RETURN)->count());
    }

    public function test_an_advance_already_used_reopens_the_invoice_it_paid_and_cash_stays_paid(): void
    {
        // R1: an advance by cheque.
        $r1   = $this->advanceReceipt($this->settlement('cheque', 20000, '410001'));
        $note = CustomerCreditNote::where('source_receipt_id', $r1->id)->firstOrFail();

        // R2: invoice B paid by that advance plus 5,000 by transfer.
        $invoiceB = $this->issuedInvoice(25000);
        $r2 = $this->confirmReceipt([
            'is_advance'  => false,
            'allocations' => [['reference_type' => 'invoice', 'reference_id' => $invoiceB->id, 'receipt_amount' => 25000, 'discount' => 0]],
            'setoffs'     => [['setoff_type' => 'advance', 'credit_note_id' => $note->id, 'amount' => 20000, 'remark' => null]],
            'settlements' => [$this->settlement('online_transfer', 5000, 'TRF-2')],
        ]);
        $this->assertSame(InvoiceStatus::Paid, $invoiceB->fresh()->status);

        // R1's cheque bounces.
        $line = $this->bankLine(PostingRole::SOURCE_CUSTOMER_RECEIPT_SETTLEMENT, (int) $r1->settlements()->value('id'));
        $this->postRecon($this->createRecon([$line => 'returned'], '5000.00', [], [
            $this->bankLine(PostingRole::SOURCE_CUSTOMER_RECEIPT_SETTLEMENT, (int) $r2->settlements()->value('id')) => 'realized',
        ]))->assertOk();

        // Invoice B owes what the advance paid; the transfer stays paid.
        $this->assertSame(20000.0, round(app(InvoiceBalanceService::class)->outstanding($invoiceB->id), 2));
        $this->assertSame(InvoiceStatus::Issued, $invoiceB->fresh()->status);

        // Dr Receivables on invoice B / Cr the bank. Customer Advances is not touched: it was spent.
        $entry = JournalEntry::where('source_type', PostingRole::SOURCE_CHEQUE_RETURN)->with('lines')->firstOrFail();
        $receivable = $entry->lines->firstWhere('ledger_account_id', $this->mapped(AccountRole::AccountsReceivable));
        $this->assertSame(20000.0, round((float) $receivable->debit, 2));
        $this->assertSame($invoiceB->id, (int) $receivable->source_id);
        $this->assertNull($entry->lines->firstWhere('ledger_account_id', $this->mapped(AccountRole::CustomerAdvance)));
        $this->assertSame(0.0, $this->balance($this->mapped(AccountRole::CustomerAdvance)));

        // R2 can no longer be voided: that would reopen invoice B a second time.
        $this->actingAs($this->user)
            ->postJson("/api/v1/customer-receipts/{$r2->id}/void", ['reason' => 'Mistake'])
            ->assertStatus(422);
        $this->assertSame('confirmed', $r2->fresh()->status->value);
    }

    public function test_a_bounce_takes_back_the_settlement_discount(): void
    {
        $invoice = $this->issuedInvoice(10000);
        $receipt = $this->confirmReceipt([
            'is_advance'  => false,
            'allocations' => [['reference_type' => 'invoice', 'reference_id' => $invoice->id, 'receipt_amount' => 9800, 'discount' => 200]],
            'settlements' => [$this->settlement('cheque', 9800, '420001')],
        ]);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);

        $line = $this->bankLine(PostingRole::SOURCE_CUSTOMER_RECEIPT_SETTLEMENT, (int) $receipt->settlements()->value('id'));
        $this->postRecon($this->createRecon([$line => 'returned'], '0.00'))->assertOk();

        // The full invoice is owed again, not 9,800.
        $this->assertSame(10000.0, round(app(InvoiceBalanceService::class)->outstanding($invoice->id), 2));

        // The bank moves only the cheque; the discount goes back through Discount Allowed.
        $entry = JournalEntry::where('source_type', PostingRole::SOURCE_CHEQUE_RETURN)->with('lines')->firstOrFail();
        $this->assertSame(9800.0, round((float) $entry->lines->firstWhere('ledger_account_id', $this->bank->id)->credit, 2));
        $this->assertSame(200.0, round((float) $entry->lines->firstWhere('ledger_account_id', $this->mapped(AccountRole::DiscountAllowed))->credit, 2));
        $this->assertSame(10000.0, round((float) $entry->lines->where('ledger_account_id', $this->mapped(AccountRole::AccountsReceivable))->sum('debit'), 2));
        $this->assertSame(0.0, $this->balance($this->mapped(AccountRole::DiscountAllowed)));
    }

    public function test_a_cheque_realized_on_an_earlier_statement_can_be_returned_on_a_later_one(): void
    {
        $invoice = $this->issuedInvoice(6000);
        $receipt = $this->receipt([[$invoice, 6000]], $this->settlement('cheque', 6000, '430001'));
        $line    = $this->bankLine(PostingRole::SOURCE_CUSTOMER_RECEIPT_SETTLEMENT, (int) $receipt->settlements()->value('id'));

        // October: the bank credits the cheque.
        $october = $this->createRecon([$line => 'realized'], '6000.00');
        $this->postRecon($october)->assertOk();

        // Next statement (to the 26th): the bank debits it back on the 23rd. Add Transaction finds it.
        $found = $this->actingAs($this->user)
            ->postJson('/api/v1/bank-reconciliations/search', ['bank_account_id' => $this->bank->id, 'by' => 'returned', 'term' => '430001'])
            ->assertOk()->json('data');
        $this->assertSame([$line], array_column($found, 'journal_line_id'));
        $this->assertTrue($found[0]['cleared_before']);
        $this->assertSame($october->recon_no, $found[0]['cleared_recon_no']);

        // A return date inside the reconciled October is refused.
        $this->actingAs($this->user)->postJson('/api/v1/bank-reconciliations', $this->reconPayload(
            [$line => ['status' => 'returned_later', 'return_date' => '2026-10-15']],
            '0.00',
            ['date_from' => '2026-10-21', 'statement_date' => '2026-10-26'],
        ))->assertUnprocessable()->assertJsonValidationErrors('lines.0.status');

        $november = $this->createRecon([], '0.00', ['date_from' => '2026-10-21', 'statement_date' => '2026-10-26'], [
            $line => ['status' => 'returned_later', 'return_date' => '2026-10-23'],
        ]);

        // The money going back moves this statement's balance: 6,000 - 6,000 = 0.
        $this->assertSame('6000.00', (string) $november->opening_balance);
        $this->assertSame('6000.00', (string) $november->cleared_withdrawals);
        $this->assertSame('0.00', (string) $november->difference);

        $this->postRecon($november)->assertOk();

        // The invoice is owed again; the bounce is dated on the return date.
        $this->assertSame(6000.0, round(app(InvoiceBalanceService::class)->outstanding($invoice->id), 2));
        $entry = JournalEntry::where('source_type', PostingRole::SOURCE_CHEQUE_RETURN)->firstOrFail();
        $this->assertSame('2026-10-23', $entry->journal_date->toDateString());
        $this->assertSame(0.0, $this->balance($this->bank->id));

        // The cheque stays cleared in October; its bounce is cleared in November; nothing is left open.
        $this->assertSame([], $this->workspace('2026-11-30')['lines']);
        $this->assertSame([], $this->actingAs($this->user)
            ->postJson('/api/v1/bank-reconciliations/search', ['bank_account_id' => $this->bank->id, 'by' => 'returned', 'term' => '430001'])
            ->json('data'));

        // Neither reconciliation can be undone now.
        $this->actingAs($this->user)
            ->postJson("/api/v1/bank-reconciliations/{$november->id}/void", ['reason' => 'Undo'])
            ->assertUnprocessable();
    }

    public function test_a_return_takes_the_date_the_bank_returned_it(): void
    {
        $invoice = $this->issuedInvoice(3000);
        $receipt = $this->receipt([[$invoice, 3000]], $this->settlement('cheque', 3000, '440001'));
        $line    = $this->bankLine(PostingRole::SOURCE_CUSTOMER_RECEIPT_SETTLEMENT, (int) $receipt->settlements()->value('id'));

        // Not before the cheque was booked (5 Oct).
        $this->actingAs($this->user)->postJson('/api/v1/bank-reconciliations', $this->reconPayload(
            [$line => ['status' => 'returned', 'return_date' => '2026-10-02']],
            '0.00',
        ))->assertUnprocessable()->assertJsonValidationErrors('lines.0.status');

        $this->postRecon($this->createRecon([], '0.00', [], [$line => ['status' => 'returned', 'return_date' => '2026-10-12']]))->assertOk();

        $this->assertSame('2026-10-12', JournalEntry::where('source_type', PostingRole::SOURCE_CHEQUE_RETURN)->firstOrFail()->journal_date->toDateString());
        $this->assertSame('2026-10-12', ChequeReturn::firstOrFail()->return_date->toDateString());
    }

    public function test_only_a_cheque_can_be_returned(): void
    {
        $invoice  = $this->issuedInvoice(2500);
        $transfer = $this->receipt([[$invoice, 2500]], $this->settlement('online_transfer', 2500, 'TRF-881'));
        $cheque   = $this->advanceReceipt($this->settlement('cheque', 1000, '500001'));

        $rows = collect($this->workspace()['lines'])->keyBy('journal_line_id');

        $transferLine = $this->bankLine(PostingRole::SOURCE_CUSTOMER_RECEIPT_SETTLEMENT, (int) $transfer->settlements()->value('id'));
        $chequeLine   = $this->bankLine(PostingRole::SOURCE_CUSTOMER_RECEIPT_SETTLEMENT, (int) $cheque->settlements()->value('id'));

        $this->assertStringContainsString('Only a cheque', (string) $rows[$transferLine]['return_blocked']);
        $this->assertNull($rows[$chequeLine]['return_blocked']);
    }

    public function test_a_deposited_cheque_is_returned_from_the_bank_it_was_deposited_to(): void
    {
        $invoice = $this->issuedInvoice(12000);
        $receipt = $this->receipt([[$invoice, 12000]], $this->settlement('cheque', 12000, '600001', $this->chequesInHand));

        $settlementId = (int) $receipt->settlements()->value('id');

        $depositId = $this->actingAs($this->user)->postJson('/api/v1/bank-deposits', [
            'request_id'      => (string) Str::uuid(),
            'company_id'      => $this->companyA,
            'deposit_date'    => '2026-10-06',
            'bank_account_id' => $this->bank->id,
            'slip_no'         => 'SLIP-1',
            'cash_account_id' => null,
            'cash_amount'     => 0,
            'cheque_ids'      => [$settlementId],
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->user)->postJson("/api/v1/bank-deposits/{$depositId}/confirm")->assertOk();

        $line = $this->bankLine(PostingRole::SOURCE_CUSTOMER_RECEIPT_SETTLEMENT, $settlementId);
        $this->postRecon($this->createRecon([$line => 'returned'], '0.00'))->assertOk();

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
        $this->assertSame(0.0, $this->balance($this->bank->id));
        $this->assertSame(0.0, $this->balance($this->chequesInHand->id));

        // The deposit cannot be voided any more: its cheque line is reconciled.
        $this->actingAs($this->user)
            ->postJson("/api/v1/bank-deposits/{$depositId}/void", ['reason' => 'Cheque bounced', 'reversal_date' => '2026-10-26'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    // ── Cheque status on the receipt / payment screens ───────────────────────

    public function test_each_cheque_line_shows_where_it_stands(): void
    {
        $inHand    = $this->advanceReceipt($this->settlement('cheque', 1000, '900001', $this->chequesInHand));
        $toDeposit = $this->advanceReceipt($this->settlement('cheque', 2000, '900002', $this->chequesInHand));
        $direct    = $this->advanceReceipt($this->settlement('cheque', 3000, '900003'));
        $cleared   = $this->advanceReceipt($this->settlement('cheque', 4000, '900004'));
        $cash      = $this->advanceReceipt($this->settlement('online_transfer', 500, 'TRF-1'));

        $depositSettlement = (int) $toDeposit->settlements()->value('id');
        $depositId = $this->actingAs($this->user)->postJson('/api/v1/bank-deposits', [
            'request_id' => (string) Str::uuid(), 'company_id' => $this->companyA, 'deposit_date' => '2026-10-06',
            'bank_account_id' => $this->bank->id, 'slip_no' => 'SLIP-2', 'cash_account_id' => null, 'cash_amount' => 0,
            'cheque_ids' => [$depositSettlement],
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->user)->postJson("/api/v1/bank-deposits/{$depositId}/confirm")->assertOk();

        $clearedLine = $this->bankLine(PostingRole::SOURCE_CUSTOMER_RECEIPT_SETTLEMENT, (int) $cleared->settlements()->value('id'));
        $recon       = $this->createRecon([$clearedLine => 'realized'], '4000.00');
        $this->postRecon($recon)->assertOk();

        $status = fn (CustomerReceipt $r): ?array => $this->actingAs($this->user)
            ->getJson("/api/v1/customer-receipts/{$r->id}")->assertOk()->json('data.settlements.0.cheque_status');

        $this->assertSame('in_hand', $status($inHand)['status']);
        $this->assertSame('deposited', $status($toDeposit)['status']);
        $this->assertStringStartsWith('BD', (string) $status($toDeposit)['reference']);
        $this->assertSame('deposited', $status($direct)['status']);
        $this->assertSame('Direct to bank', $status($direct)['reference']);
        $this->assertSame(['status' => 'realized', 'label' => 'Realized', 'reference' => $recon->recon_no, 'date' => '2026-10-20'], $status($cleared));
        $this->assertNull($status($cash), 'A transfer is not a cheque.');
    }

    public function test_returned_cheques_show_on_the_lists_and_can_be_filtered(): void
    {
        $invoice = $this->issuedInvoice(5000);
        $bounced = $this->receipt([[$invoice, 5000]], $this->settlement('cheque', 5000, '910001'));
        $fine    = $this->advanceReceipt($this->settlement('cheque', 700, '910002'));

        $line = $this->bankLine(PostingRole::SOURCE_CUSTOMER_RECEIPT_SETTLEMENT, (int) $bounced->settlements()->value('id'));
        $this->postRecon($this->createRecon([$line => 'returned'], '0.00'))->assertOk();

        $all = collect($this->actingAs($this->user)->getJson('/api/v1/customer-receipts')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(1, $all[$bounced->id]['returned_cheques']);
        $this->assertSame(0, $all[$fine->id]['returned_cheques']);

        $this->actingAs($this->user)->getJson('/api/v1/customer-receipts?cheque_returned=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $bounced->id);

        $this->actingAs($this->user)->getJson("/api/v1/customer-receipts/{$bounced->id}")
            ->assertJsonPath('data.settlements.0.cheque_status.status', 'returned');
    }

    // ── Issued cheques ───────────────────────────────────────────────────────

    public function test_a_returned_supplier_cheque_reopens_the_grn(): void
    {
        $grn = GoodsReceivedNote::create([
            'grn_no'       => 'GRN-TST-0001',
            'supplier_id'  => $this->supplier->id,
            'grn_date'     => '2026-10-01',
            'status'       => 'confirmed',
            'total_amount' => 20000,
        ]);

        $paymentId = (int) $this->actingAs($this->user)->postJson('/api/v1/supplier-payments', [
            'payment_date' => '2026-10-05',
            'supplier_id'  => $this->supplier->id,
            'is_advance'   => false,
            'allocations'  => [['reference_type' => 'grn', 'reference_id' => $grn->id, 'payment_amount' => 20000, 'discount' => 0]],
            'setoffs'      => [],
            'settlements'  => [$this->settlement('cheque', 20000, '700001')],
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->user)->postJson("/api/v1/supplier-payments/{$paymentId}/confirm")->assertOk();
        $this->assertSame(0.0, round(app(GrnBalanceService::class)->outstanding($grn->id), 2));

        $payment = SupplierPayment::findOrFail($paymentId);
        $line    = $this->bankLine(PostingRole::SOURCE_SUPPLIER_PAYMENT_SETTLEMENT, (int) $payment->settlements()->value('id'));

        $this->postRecon($this->createRecon([$line => 'returned'], '0.00'))->assertOk();

        $this->assertSame(20000.0, round(app(GrnBalanceService::class)->outstanding($grn->id), 2));

        // Dr the bank / Cr Payables on the GRN.
        $entry   = JournalEntry::where('source_type', PostingRole::SOURCE_CHEQUE_RETURN)->with('lines')->firstOrFail();
        $payable = $entry->lines->firstWhere('ledger_account_id', $this->mapped(AccountRole::AccountsPayable));
        $this->assertSame(20000.0, round((float) $payable->credit, 2));
        $this->assertSame('grn', $payable->source_type);
        $this->assertSame(20000.0, round((float) $entry->lines->firstWhere('ledger_account_id', $this->bank->id)->debit, 2));
        $this->assertSame(0.0, $this->balance($this->bank->id));

        $this->actingAs($this->user)->postJson("/api/v1/supplier-payments/{$paymentId}/void")->assertStatus(422);

        $this->actingAs($this->user)->getJson("/api/v1/supplier-payments/{$paymentId}")
            ->assertJsonPath('data.returned_cheques', 1)
            ->assertJsonPath('data.settlements.0.cheque_status.status', 'returned');
        $this->actingAs($this->user)->getJson('/api/v1/supplier-payments?cheque_returned=1')
            ->assertJsonCount(1, 'data');
    }

    public function test_a_returned_supplier_cheque_gives_back_the_discount_taken(): void
    {
        $grn = GoodsReceivedNote::create([
            'grn_no' => 'GRN-TST-0002', 'supplier_id' => $this->supplier->id, 'grn_date' => '2026-10-01',
            'status' => 'confirmed', 'total_amount' => 20000,
        ]);

        $paymentId = (int) $this->actingAs($this->user)->postJson('/api/v1/supplier-payments', [
            'payment_date' => '2026-10-05',
            'supplier_id'  => $this->supplier->id,
            'is_advance'   => false,
            'allocations'  => [['reference_type' => 'grn', 'reference_id' => $grn->id, 'payment_amount' => 19500, 'discount' => 500]],
            'setoffs'      => [],
            'settlements'  => [$this->settlement('cheque', 19500, '710001')],
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->user)->postJson("/api/v1/supplier-payments/{$paymentId}/confirm")->assertOk();

        $line = $this->bankLine(PostingRole::SOURCE_SUPPLIER_PAYMENT_SETTLEMENT, (int) SupplierPayment::findOrFail($paymentId)->settlements()->value('id'));
        $this->postRecon($this->createRecon([$line => 'returned'], '0.00'))->assertOk();

        // The whole GRN is owed again; Discount Received gives the 500 back.
        $this->assertSame(20000.0, round(app(GrnBalanceService::class)->outstanding($grn->id), 2));

        $entry = JournalEntry::where('source_type', PostingRole::SOURCE_CHEQUE_RETURN)->with('lines')->firstOrFail();
        $this->assertSame(500.0, round((float) $entry->lines->firstWhere('ledger_account_id', $this->mapped(AccountRole::DiscountReceived))->debit, 2));
        $this->assertSame(20000.0, round((float) $entry->lines->where('ledger_account_id', $this->mapped(AccountRole::AccountsPayable))->sum('credit'), 2));
        $this->assertSame(19500.0, round((float) $entry->lines->firstWhere('ledger_account_id', $this->bank->id)->debit, 2));
    }

    public function test_a_returned_bill_payment_cheque_reopens_the_bill(): void
    {
        $bill = $this->confirmedBill([
            'transaction_date' => '2026-10-01', 'document_date' => '2026-10-01', 'due_date' => '2026-10-31',
        ]);

        $paymentId = $this->actingAs($this->user)->postJson('/api/v1/bill-payments', [
            'request_id'          => (string) Str::uuid(),
            'supplier_id'         => $this->supplier->id,
            'company_id'          => $this->companyA,
            'payment_date'        => '2026-10-05',
            'pay_from_account_id' => $this->bank->id,
            'payment_mode_id'     => $this->modes['cheque']->id,
            'cheque_no'           => '800001',
            'cheque_date'         => '2026-10-05',
            'allocations'         => [['bill_id' => $bill->id, 'amount' => '15000.50']],
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->user)->postJson("/api/v1/bill-payments/{$paymentId}/confirm")->assertOk();
        $this->assertSame(PaymentStatus::Paid, $bill->fresh()->payment_status);

        $line  = $this->bankLine(PostingRole::SOURCE_BILL_PAYMENT, $paymentId);
        $recon = $this->createRecon([$line => 'returned'], '0.00');
        $this->postRecon($recon)->assertOk();

        $bill->refresh();
        $this->assertSame(PaymentStatus::Unpaid, $bill->payment_status);
        $this->assertSame('15000.50', (string) $bill->balance());

        $payment = BillPayment::findOrFail($paymentId);
        $this->assertSame($recon->recon_no, $payment->return_reference);
        $this->assertNotNull($payment->return_journal_no);
        $this->assertSame(0.0, $this->balance($this->bank->id));

        $this->actingAs($this->user)
            ->postJson("/api/v1/bill-payments/{$paymentId}/void", ['reason' => 'Cheque bounced', 'reversal_date' => '2026-10-26'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function moneyAccount(string $name, CashBookType $type, bool $cheque): LedgerAccount
    {
        $control = ControlAccount::where('control_account_name', 'Cash & Cash Equivalents')->firstOrFail();

        return app(LedgerAccountService::class)->create(new LedgerAccountData(
            control_account_id:  $control->id,
            ledger_account_name: $name,
            description:         null,
            is_active:           true,
            cash_book_type:      $type,
            company_id:          $this->companyA,
            allows_cash:         false,
            allows_cheque:       $cheque,
            bank_id:             null,
            bank_branch_id:      null,
            bank_account_no:     null,
        ));
    }

    private function issuedInvoice(float $grandTotal): Invoice
    {
        static $seq = 0;
        $seq++;

        return Invoice::create([
            'invoice_no'   => sprintf('INV-CR-%04d', $seq),
            'so_id'        => $seq,
            'customer_id'  => $this->customer->id,
            'invoice_date' => '2026-10-01',
            'due_date'     => '2026-10-31',
            'status'       => 'issued',
            'invoice_type' => 'non_tax',
            'subtotal'     => $grandTotal,
            'grand_total'  => $grandTotal,
        ]);
    }

    /** @return array<string, mixed> */
    private function settlement(string $mode, float $amount, string $reference, ?LedgerAccount $into = null): array
    {
        return [
            'payment_mode_id'   => $this->modes[$mode]->id,
            'amount'            => $amount,
            'ledger_account_id' => ($into ?? $this->bank)->id,
            'reference_no'      => $reference,
            'instrument_date'   => '2026-10-05',
            'bank_name'         => 'Commercial Bank',
        ];
    }

    /**
     * @param list<array{0: Invoice, 1: float}> $invoices
     * @param array<string, mixed>              $settlement
     */
    private function receipt(array $invoices, array $settlement, string $date = '2026-10-05'): CustomerReceipt
    {
        return $this->confirmReceipt([
            'receipt_date' => $date,
            'is_advance'  => false,
            'allocations' => array_map(fn (array $i): array => [
                'reference_type' => 'invoice', 'reference_id' => $i[0]->id, 'receipt_amount' => $i[1], 'discount' => 0,
            ], $invoices),
            'settlements' => [$settlement],
        ]);
    }

    /** @param array<string, mixed> $settlement */
    private function advanceReceipt(array $settlement): CustomerReceipt
    {
        return $this->confirmReceipt([
            'is_advance'     => true,
            'advance_amount' => $settlement['amount'],
            'allocations'    => [],
            'settlements'    => [$settlement],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function confirmReceipt(array $payload): CustomerReceipt
    {
        $id = (int) $this->actingAs($this->user)->postJson('/api/v1/customer-receipts', [
            'receipt_date' => '2026-10-05',
            'customer_id'  => $this->customer->id,
            'setoffs'      => [],
            ...$payload,
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->user)->postJson("/api/v1/customer-receipts/{$id}/confirm")->assertOk();

        return CustomerReceipt::findOrFail($id);
    }

    /** The bank line naming this instrument. */
    private function bankLine(string $sourceType, int $sourceId): int
    {
        return (int) JournalLine::where('ledger_account_id', $this->bank->id)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->value('id');
    }

    /**
     * @param  array<int, string|array{status:string, return_date?:string}> $lines journal_line_id => status
     * @param  array<string, mixed>                                         $overrides
     * @return array<string, mixed>
     */
    private function reconPayload(array $lines, string $statementBalance, array $overrides = []): array
    {
        return array_merge([
            'request_id'        => (string) Str::uuid(),
            'company_id'        => $this->companyA,
            'bank_account_id'   => $this->bank->id,
            'date_from'         => '2026-10-01',
            'statement_date'    => '2026-10-20',
            'opening_balance'   => '0.00',
            'statement_balance' => $statementBalance,
            'lines'             => collect($lines)->map(fn (string|array $line, int $id): array => [
                'journal_line_id' => $id,
                ...(is_array($line) ? $line : ['status' => $line]),
            ])->values()->all(),
        ], $overrides);
    }

    /**
     * @param array<int, string|array{status:string, return_date?:string}> $lines journal_line_id => status
     * @param array<string, mixed>                                         $overrides
     * @param array<int, string|array{status:string, return_date?:string}> $more more lines
     */
    private function createRecon(array $lines, string $statementBalance, array $overrides = [], array $more = []): BankReconciliation
    {
        $id = $this->actingAs($this->user)
            ->postJson('/api/v1/bank-reconciliations', $this->reconPayload($lines + $more, $statementBalance, $overrides))
            ->assertCreated()
            ->json('data.id');

        return BankReconciliation::findOrFail($id);
    }

    private function postRecon(BankReconciliation $recon): TestResponse
    {
        return $this->actingAs($this->user)->postJson("/api/v1/bank-reconciliations/{$recon->id}/post");
    }

    /** @return array<string, mixed> */
    private function workspace(string $date = '2026-10-20'): array
    {
        return $this->actingAs($this->user)
            ->getJson('/api/v1/bank-reconciliations/workspace?bank_account_id=' . $this->bank->id . '&statement_date=' . $date)
            ->assertOk()
            ->json('data');
    }

    private function balance(int $accountId): float
    {
        $lines = JournalLine::inLedger()->where('ledger_account_id', $accountId)->get(['debit', 'credit']);

        return round($lines->sum(fn ($l): float => (float) $l->debit - (float) $l->credit), 2);
    }
}
