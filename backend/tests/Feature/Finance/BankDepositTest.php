<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\PaymentModeType;
use App\Models\PaymentMode;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\Journal\PostingRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Finance\DTOs\LedgerAccountData;
use Modules\Finance\Enums\BankDepositStatus;
use Modules\Finance\Enums\CashBookType;
use Modules\Finance\Models\BankDeposit;
use Modules\Finance\Models\ControlAccount;
use Modules\Finance\Models\JournalEntry;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Services\FinanceControlService;
use Modules\Finance\Services\LedgerAccountService;
use Modules\Inventory\Models\CustomerMaster;
use Modules\Inventory\Models\CustomerReceipt;
use Modules\Inventory\Models\CustomerReceiptSettlement;
use Tests\Feature\Finance\Concerns\BillFixtures;
use Tests\TestCase;

/**
 * Bank Deposit: cash from a Cash Book and received cheques from Cheques in
 * Hand, paid into a Bank account on one slip.
 *
 * The receipts are real: confirmed through the Customer Receipt API, so the
 * cash and cheques sit in the ledger exactly as they would in production.
 * Confirming a deposit posts Dr Bank / Cr Cash Book + Cheques in Hand; voiding
 * it reverses that and puts the cheques back in hand.
 */
class BankDepositTest extends TestCase
{
    use BillFixtures;
    use RefreshDatabase;

    private CustomerMaster $customer;
    private LedgerAccount  $cash;
    private LedgerAccount  $chequesInHand;
    private LedgerAccount  $bank;

    /** @var array<string, PaymentMode> keyed by code */
    private array $modes = [];

    private int $chequeSeq = 100000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->billPermissions = [
            ...$this->billPermissions,
            'view_bank_deposits', 'create_bank_deposits', 'edit_bank_deposits',
            'delete_bank_deposits', 'confirm_bank_deposits', 'void_bank_deposits',
            'view_customer_receipts', 'create_customer_receipts', 'confirm_customer_receipts',
            'void_customer_receipts',
        ];

        $this->setUpBills();

        app(SettingsService::class)->set('module.inventory', true);
        $this->user->forceFill(['active_modules' => ['inventory', 'finance']])->save();

        $this->cash          = $this->moneyAccount('Main Cash', CashBookType::CashBook, cash: true, cheque: false);
        $this->chequesInHand = $this->moneyAccount('Cheques in Hand', CashBookType::CashBook, cash: false, cheque: true);
        $this->bank          = $this->moneyAccount('Sampath Current', CashBookType::Bank, cash: false, cheque: false);

        foreach ([
            ['Cash', 'cash', PaymentModeType::Cash],
            ['Cheque', 'cheque', PaymentModeType::Cheque],
        ] as $i => [$name, $code, $type]) {
            $this->modes[$code] = PaymentMode::updateOrCreate(['code' => $code], [
                'payment_mode_name' => $name, 'type' => $type, 'sort_order' => $i + 1, 'is_active' => true,
            ]);
        }

        $this->customer = CustomerMaster::create([
            'customer_code' => 'CUS-0001',
            'customer_name' => 'Nimal Stores',
            'customer_type' => 'Retail',
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function moneyAccount(string $name, CashBookType $type, bool $cash, bool $cheque, ?int $companyId = null): LedgerAccount
    {
        $control = ControlAccount::where('control_account_name', 'Cash & Cash Equivalents')->firstOrFail();

        return app(LedgerAccountService::class)->create(new LedgerAccountData(
            control_account_id:  $control->id,
            ledger_account_name: $name,
            description:         null,
            is_active:           true,
            cash_book_type:      $type,
            company_id:          $companyId ?? $this->companyA,
            allows_cash:         $cash,
            allows_cheque:       $cheque,
            bank_id:             null,
            bank_branch_id:      null,
            bank_account_no:     null,
        ));
    }

    /** A confirmed advance receipt paid by one cheque into the given account. Returns the settlement id. */
    private function receivedCheque(float $amount, string $chequeDate = '2026-10-20', ?LedgerAccount $into = null): int
    {
        $receipt = $this->confirmedReceipt($amount, [
            'payment_mode_id'   => $this->modes['cheque']->id,
            'amount'            => $amount,
            'ledger_account_id' => ($into ?? $this->chequesInHand)->id,
            'reference_no'      => (string) ++$this->chequeSeq,
            'instrument_date'   => $chequeDate,
            'bank_name'         => 'Commercial Bank',
        ]);

        return (int) $receipt->settlements()->value('id');
    }

    private function receivedCash(float $amount): CustomerReceipt
    {
        return $this->confirmedReceipt($amount, [
            'payment_mode_id'   => $this->modes['cash']->id,
            'amount'            => $amount,
            'ledger_account_id' => $this->cash->id,
        ]);
    }

    /** @param array<string, mixed> $settlement */
    private function confirmedReceipt(float $amount, array $settlement): CustomerReceipt
    {
        $id = (int) $this->actingAs($this->user)->postJson('/api/v1/customer-receipts', [
            'receipt_date'   => '2026-10-20',
            'customer_id'    => $this->customer->id,
            'is_advance'     => true,
            'advance_amount' => $amount,
            'allocations'    => [],
            'setoffs'        => [],
            'settlements'    => [$settlement],
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->user)->postJson("/api/v1/customer-receipts/{$id}/confirm")->assertOk();

        return CustomerReceipt::findOrFail($id);
    }

    /**
     * @param  array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'request_id'      => (string) Str::uuid(),
            'company_id'      => $this->companyA,
            'deposit_date'    => '2026-10-26',
            'bank_account_id' => $this->bank->id,
            'slip_no'         => 'SLIP-77',
            'cash_account_id' => null,
            'cash_amount'     => 0,
            'cheque_ids'      => [],
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function createDeposit(array $overrides = []): BankDeposit
    {
        $id = $this->actingAs($this->user)
            ->postJson('/api/v1/bank-deposits', $this->payload($overrides))
            ->assertCreated()
            ->json('data.id');

        return BankDeposit::findOrFail($id);
    }

    private function confirm(BankDeposit $deposit, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->user)->postJson("/api/v1/bank-deposits/{$deposit->id}/confirm");
    }

    /** @return list<int> settlement ids the in-hand endpoint offers */
    private function chequesOffered(?int $depositId = null): array
    {
        return collect($this->actingAs($this->user)
            ->getJson('/api/v1/bank-deposits/in-hand?company_id=' . $this->companyA . '&deposit_date=2026-10-26'
                . ($depositId ? '&deposit_id=' . $depositId : ''))
            ->assertOk()
            ->json('data.cheques'))->pluck('settlement_id')->all();
    }

    /** Debits minus credits for one account, across everything in the ledger. */
    private function balance(int $accountId): float
    {
        $lines = JournalLine::inLedger()->where('ledger_account_id', $accountId)->get(['debit', 'credit']);

        return round($lines->sum(fn ($l): float => (float) $l->debit - (float) $l->credit), 2);
    }

    // ── What is waiting to be banked ─────────────────────────────────────────

    public function test_in_hand_lists_cash_available_and_cheques_with_post_dated_ones_blocked(): void
    {
        $this->receivedCash(12000);
        $due  = $this->receivedCheque(5000);
        $pdc  = $this->receivedCheque(7000, chequeDate: '2026-11-15');
        // Received straight into the bank: already banked, never offered.
        $this->receivedCheque(3000, into: $this->bank);

        $data = $this->actingAs($this->user)
            ->getJson('/api/v1/bank-deposits/in-hand?company_id=' . $this->companyA . '&deposit_date=2026-10-26')
            ->assertOk()
            ->json('data');

        $cash = collect($data['cash_accounts'])->firstWhere('id', $this->cash->id);
        $this->assertSame('12000.00', $cash['available']);

        $cheques = collect($data['cheques'])->keyBy('settlement_id');
        $this->assertSame([$due, $pdc], $cheques->keys()->all());
        $this->assertSame('5000.00', $cheques[$due]['amount']);
        $this->assertSame('Nimal Stores', $cheques[$due]['customer_name']);
        $this->assertNull($cheques[$due]['blocked']);
        $this->assertStringContainsString('post-dated', (string) $cheques[$pdc]['blocked']);
    }

    public function test_cheques_in_a_cash_book_are_not_cash(): void
    {
        // One book taking both: its ledger balance is 7,000, but only 2,000 is notes.
        $mixed = $this->moneyAccount('Cash in Hand', CashBookType::CashBook, cash: true, cheque: true);
        $this->confirmedReceipt(2000, [
            'payment_mode_id' => $this->modes['cash']->id, 'amount' => 2000, 'ledger_account_id' => $mixed->id,
        ]);
        $this->receivedCheque(5000, into: $mixed);

        $cash = collect($this->actingAs($this->user)
            ->getJson('/api/v1/bank-deposits/in-hand?company_id=' . $this->companyA . '&deposit_date=2026-10-26')
            ->assertOk()->json('data.cash_accounts'))->firstWhere('id', $mixed->id);
        $this->assertSame('2000.00', $cash['available']);

        $this->actingAs($this->user)
            ->postJson('/api/v1/bank-deposits', $this->payload(['cash_account_id' => $mixed->id, 'cash_amount' => '7000.00']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cash_amount');
    }

    // ── Create and confirm ───────────────────────────────────────────────────

    public function test_confirm_moves_cash_and_cheques_into_the_bank(): void
    {
        $this->receivedCash(12000);
        $first  = $this->receivedCheque(5000);
        $second = $this->receivedCheque(2500.50);

        $deposit = $this->createDeposit([
            'cash_account_id' => $this->cash->id,
            'cash_amount'     => '10000.00',
            'cheque_ids'      => [$first, $second],
        ]);

        $this->assertSame(BankDepositStatus::Draft, $deposit->status);
        $this->assertSame('BD-2026-0001', $deposit->deposit_no);
        $this->assertSame('10000.00', (string) $deposit->cash_total);
        $this->assertSame('7500.50', (string) $deposit->cheque_total);
        $this->assertSame('17500.50', (string) $deposit->total_amount);

        // A draft moves no money.
        $this->assertSame(0.0, $this->balance($this->bank->id));

        $this->confirm($deposit)
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.cheque_count', 2);

        $this->assertSame(17500.50, $this->balance($this->bank->id));
        $this->assertSame(2000.0, $this->balance($this->cash->id));
        $this->assertSame(0.0, $this->balance($this->chequesInHand->id));

        $journal = JournalEntry::where('source_type', PostingRole::SOURCE_BANK_DEPOSIT)
            ->where('source_id', $deposit->id)->with('lines')->firstOrFail();
        $this->assertSame((int) $this->companyA, (int) $journal->company_id);
        // One credit per cheque, so Cheques in Hand lists each one that left it.
        $this->assertCount(2, $journal->lines->where('ledger_account_id', $this->chequesInHand->id));

        $this->assertSame([], $this->chequesOffered());
    }

    // ── Instrument on the bank line (Bank Reconciliation groundwork) ─────────

    public function test_the_bank_side_is_one_line_per_cheque_carrying_its_number_and_date(): void
    {
        $this->receivedCash(12000);
        $first  = $this->receivedCheque(5000, chequeDate: '2026-10-18');
        $second = $this->receivedCheque(2500.50);

        $deposit = $this->createDeposit([
            'cash_account_id' => $this->cash->id,
            'cash_amount'     => '10000.00',
            'cheque_ids'      => [$first, $second],
        ]);
        $this->confirm($deposit)->assertOk();

        $bankLines = JournalLine::where('ledger_account_id', $this->bank->id)->orderBy('line_no')->get();

        // Two cheques and the cash: three lines, so each clears on its own day.
        $this->assertCount(3, $bankLines);
        $this->assertSame(17500.50, round((float) $bankLines->sum('debit'), 2));

        $cheque = $bankLines->firstWhere('source_id', $first);
        $this->assertSame(PostingRole::SOURCE_CUSTOMER_RECEIPT_SETTLEMENT, $cheque->source_type);
        $this->assertSame('cheque', $cheque->instrument_type);
        $this->assertSame((string) CustomerReceiptSettlement::find($first)->reference_no, $cheque->instrument_no);
        $this->assertSame('2026-10-18', $cheque->instrument_date->toDateString());
        $this->assertSame('5000.0000', (string) $cheque->debit);

        $cash = $bankLines->firstWhere('instrument_type', 'cash');
        $this->assertSame('10000.0000', (string) $cash->debit);
        $this->assertSame('SLIP-77', $cash->instrument_no);
    }

    public function test_a_received_cheque_line_names_its_settlement_and_the_reversal_keeps_it(): void
    {
        $settlement = $this->receivedCheque(5000, chequeDate: '2026-10-19');
        $deposit    = $this->createDeposit(['cheque_ids' => [$settlement]]);
        $this->confirm($deposit)->assertOk();

        $inHand = JournalLine::where('ledger_account_id', $this->chequesInHand->id)->where('debit', '>', 0)->firstOrFail();
        $this->assertSame(PostingRole::SOURCE_CUSTOMER_RECEIPT_SETTLEMENT, $inHand->source_type);
        $this->assertSame($settlement, $inHand->source_id);
        $this->assertSame('cheque', $inHand->instrument_type);
        $this->assertSame('2026-10-19', $inHand->instrument_date->toDateString());

        $this->actingAs($this->user)->postJson("/api/v1/bank-deposits/{$deposit->id}/void", [
            'reason' => 'Wrong bank account', 'reversal_date' => '2026-10-26',
        ])->assertOk();

        // The mirror credit on the bank carries the same cheque, so the pair
        // can be recognised and netted off in Bank Reconciliation.
        $mirror = JournalLine::where('ledger_account_id', $this->bank->id)->where('credit', '>', 0)->firstOrFail();
        $this->assertSame($settlement, $mirror->source_id);
        $this->assertSame(CustomerReceiptSettlement::find($settlement)->reference_no, $mirror->instrument_no);
    }

    public function test_a_client_added_cheque_mode_is_treated_as_a_cheque(): void
    {
        $pdc = PaymentMode::create([
            'payment_mode_name' => 'Post-dated Cheque', 'code' => 'pdc', 'type' => PaymentModeType::Cheque,
            'sort_order' => 9, 'is_active' => true,
        ]);

        $receipt = $this->confirmedReceipt(4000, [
            'payment_mode_id'   => $pdc->id,
            'amount'            => 4000,
            'ledger_account_id' => $this->chequesInHand->id,
            'reference_no'      => '445566',
            'instrument_date'   => '2026-10-20',
        ]);

        $this->assertContains((int) $receipt->settlements()->value('id'), $this->chequesOffered());
        $this->assertSame('cheque', JournalLine::where('ledger_account_id', $this->chequesInHand->id)->value('instrument_type'));
    }

    public function test_amounts_and_cheque_details_come_from_the_receipt_not_the_client(): void
    {
        $cheque = $this->receivedCheque(5000);

        $deposit = $this->createDeposit([
            'cheque_ids'   => [$cheque],
            'total_amount' => '999999',
            'cheque_total' => '999999',
            'status'       => 'confirmed',
        ]);

        $this->assertSame(BankDepositStatus::Draft, $deposit->status);
        $this->assertSame('5000.00', (string) $deposit->total_amount);
        $this->assertSame('Commercial Bank', $deposit->lines()->first()->drawee_bank);
    }

    public function test_a_retried_create_returns_the_same_deposit(): void
    {
        $cheque  = $this->receivedCheque(5000);
        $payload = $this->payload(['cheque_ids' => [$cheque]]);

        $first  = $this->actingAs($this->user)->postJson('/api/v1/bank-deposits', $payload)->assertCreated()->json('data.id');
        $second = $this->actingAs($this->user)->postJson('/api/v1/bank-deposits', $payload)->assertCreated()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, BankDeposit::count());
    }

    // ── One cheque, one slip ─────────────────────────────────────────────────

    public function test_a_cheque_on_a_draft_cannot_go_on_a_second_deposit_until_the_draft_is_deleted(): void
    {
        $cheque = $this->receivedCheque(5000);
        $draft  = $this->createDeposit(['cheque_ids' => [$cheque]]);

        $this->assertSame([], $this->chequesOffered());
        // ...but the draft that holds it still sees it when edited.
        $this->assertSame([$cheque], $this->chequesOffered($draft->id));

        $this->actingAs($this->user)
            ->postJson('/api/v1/bank-deposits', $this->payload(['cheque_ids' => [$cheque]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('cheque_ids.0');

        $this->actingAs($this->user)->deleteJson("/api/v1/bank-deposits/{$draft->id}")->assertNoContent();

        $this->createDeposit(['cheque_ids' => [$cheque]]);
    }

    public function test_editing_a_draft_can_swap_its_cheques(): void
    {
        $first  = $this->receivedCheque(5000);
        $second = $this->receivedCheque(800);
        $draft  = $this->createDeposit(['cheque_ids' => [$first]]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/bank-deposits/{$draft->id}", $this->payload([
                'cheque_ids' => [$first, $second],
                'updated_at' => $draft->updated_at->toISOString(),
            ]))
            ->assertOk()
            ->assertJsonPath('data.total_amount', '5800.00')
            ->assertJsonCount(2, 'data.cheques');
    }

    // ── Rules ────────────────────────────────────────────────────────────────

    public function test_a_post_dated_cheque_cannot_be_deposited_before_its_date(): void
    {
        $pdc = $this->receivedCheque(7000, chequeDate: '2026-11-15');

        $this->actingAs($this->user)
            ->postJson('/api/v1/bank-deposits', $this->payload(['cheque_ids' => [$pdc]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('cheque_ids.0');
    }

    public function test_cash_cannot_exceed_what_the_cash_book_holds(): void
    {
        $this->receivedCash(1000);

        $this->actingAs($this->user)
            ->postJson('/api/v1/bank-deposits', $this->payload([
                'cash_account_id' => $this->cash->id,
                'cash_amount'     => '1000.01',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('cash_amount');
    }

    public function test_an_empty_deposit_is_refused(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/bank-deposits', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('cheque_ids');
    }

    public function test_the_bank_and_cash_book_must_belong_to_the_deposit_company(): void
    {
        $otherBank = $this->moneyAccount('Beta Bank', CashBookType::Bank, false, false, $this->companyB);
        $otherCash = $this->moneyAccount('Beta Cash', CashBookType::CashBook, true, false, $this->companyB);

        $this->actingAs($this->user)
            ->postJson('/api/v1/bank-deposits', $this->payload(['bank_account_id' => $otherBank->id, 'cash_amount' => 1, 'cash_account_id' => $this->cash->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('bank_account_id');

        $this->actingAs($this->user)
            ->postJson('/api/v1/bank-deposits', $this->payload(['cash_account_id' => $otherCash->id, 'cash_amount' => 1]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('cash_account_id');
    }

    public function test_confirm_rechecks_cash_spent_since_the_draft(): void
    {
        $this->receivedCash(1000);

        $first  = $this->createDeposit(['cash_account_id' => $this->cash->id, 'cash_amount' => '1000']);
        $second = $this->createDeposit(['cash_account_id' => $this->cash->id, 'cash_amount' => '1000']);

        $this->confirm($first)->assertOk();
        $this->confirm($second)->assertStatus(422)->assertJsonValidationErrors('cash_amount');
    }

    // ── Void ─────────────────────────────────────────────────────────────────

    public function test_void_reverses_the_journal_and_puts_the_cheques_back_in_hand(): void
    {
        $cheque  = $this->receivedCheque(5000);
        $deposit = $this->createDeposit(['cheque_ids' => [$cheque]]);
        $this->confirm($deposit)->assertOk();

        $this->actingAs($this->user)
            ->postJson("/api/v1/bank-deposits/{$deposit->id}/void", [
                'reason' => 'Wrong bank account', 'reversal_date' => '2026-10-26',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'voided');

        $this->assertSame(0.0, $this->balance($this->bank->id));
        $this->assertSame(5000.0, $this->balance($this->chequesInHand->id));
        $this->assertSame([$cheque], $this->chequesOffered());

        // A voided deposit is history, never edited or confirmed again.
        $this->confirm($deposit)->assertStatus(422);
        $this->actingAs($this->user)->deleteJson("/api/v1/bank-deposits/{$deposit->id}")->assertStatus(422);
    }

    public function test_a_receipt_whose_cheque_is_on_a_deposit_cannot_be_voided(): void
    {
        $cheque  = $this->receivedCheque(5000);
        $receipt = (int) CustomerReceiptSettlement::whereKey($cheque)->value('receipt_id');
        $deposit = $this->createDeposit(['cheque_ids' => [$cheque]]);

        // Held by a draft is enough: the cheque is already on a slip.
        $this->actingAs($this->user)
            ->postJson("/api/v1/customer-receipts/{$receipt}/void", ['reason' => 'Bounced'])
            ->assertStatus(422);
        $this->assertSame(5000.0, $this->balance($this->chequesInHand->id));

        // Once the deposit lets go of it, the receipt can be voided again.
        $this->actingAs($this->user)->deleteJson("/api/v1/bank-deposits/{$deposit->id}")->assertNoContent();

        $this->actingAs($this->user)
            ->postJson("/api/v1/customer-receipts/{$receipt}/void", ['reason' => 'Bounced'])
            ->assertOk();
        $this->assertSame([], $this->chequesOffered());
    }

    // ── Deposit slip ─────────────────────────────────────────────────────────

    public function test_the_slip_prints_for_a_draft_but_not_once_voided(): void
    {
        $deposit = $this->createDeposit(['cheque_ids' => [$this->receivedCheque(5000)]]);

        $this->actingAs($this->user)
            ->get("/api/v1/bank-deposits/{$deposit->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->confirm($deposit)->assertOk();
        $this->actingAs($this->user)->postJson("/api/v1/bank-deposits/{$deposit->id}/void", [
            'reason' => 'Wrong bank', 'reversal_date' => '2026-10-26',
        ])->assertOk();

        $this->actingAs($this->user)->getJson("/api/v1/bank-deposits/{$deposit->id}/pdf")->assertStatus(422);
    }

    // ── Controls ─────────────────────────────────────────────────────────────

    public function test_maker_checker_stops_the_creator_confirming(): void
    {
        app(FinanceControlService::class)->setMakerChecker(true);

        $deposit = $this->createDeposit(['cheque_ids' => [$this->receivedCheque(5000)]]);

        $this->confirm($deposit)->assertStatus(422);
        $this->confirm($deposit, $this->makeUser($this->billPermissions))->assertOk();
    }

    public function test_each_action_needs_its_permission(): void
    {
        $deposit = $this->createDeposit(['cheque_ids' => [$this->receivedCheque(5000)]]);

        $viewer = $this->makeUser(['view_bank_deposits']);

        $this->actingAs($viewer)->getJson('/api/v1/bank-deposits')->assertOk();
        $this->actingAs($viewer)->postJson('/api/v1/bank-deposits', $this->payload())->assertForbidden();
        $this->confirm($deposit, $viewer)->assertForbidden();

        $this->actingAs($this->makeUser([]))->getJson('/api/v1/bank-deposits')->assertForbidden();
    }

    public function test_finance_switched_off_closes_every_route(): void
    {
        app(SettingsService::class)->set('module.finance', false);

        $this->actingAs($this->user)->getJson('/api/v1/bank-deposits')->assertForbidden();
    }
}
