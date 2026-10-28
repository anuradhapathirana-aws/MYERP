<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\PaymentModeType;
use App\Models\AuditLog;
use App\Models\PaymentMode;
use App\Models\SupplierMaster;
use App\Services\CompanyAccessService;
use App\Services\SettingsService;
use App\Support\Journal\PostingRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Finance\Enums\AccountRole;
use Modules\Finance\Enums\BillPaymentStatus;
use Modules\Finance\Enums\CashBookType;
use Modules\Finance\Enums\PaymentStatus;
use Modules\Finance\Models\Bill;
use Modules\Finance\Models\BillPayment;
use Modules\Finance\Models\JournalEntry;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Services\AttachmentService;
use Modules\Finance\Services\FinanceControlService;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Finance\Concerns\BillFixtures;
use Tests\TestCase;

/**
 * Bill Payment: pick a supplier's open bills, pay by a shared Payment Mode
 * from a Cash Book (Cash type) or Bank (every other type),
 * confirm (Dr AP per bill / Cr cash-bank, bills' balances fall), void
 * (reverse on a chosen date, balances come back).
 */
class BillPaymentTest extends TestCase
{
    use BillFixtures;
    use RefreshDatabase;

    private LedgerAccount $cash;
    private LedgerAccount $bank;

    /** @var array<string, PaymentMode> keyed by code */
    private array $modes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->billPermissions = [
            ...$this->billPermissions,
            'view_bill_payments', 'create_bill_payments', 'edit_bill_payments',
            'delete_bill_payments', 'confirm_bill_payments', 'void_bill_payments',
        ];

        $this->setUpBills();

        $this->cash       = $this->ledger('Main Cash', 'Cash & Cash Equivalents', CashBookType::CashBook, $this->companyA);
        $this->bank       = $this->ledger('Sampath Current', 'Cash & Cash Equivalents', CashBookType::Bank, $this->companyA);

        foreach ([
            ['Cash', 'cash', PaymentModeType::Cash, false],
            ['Cheque', 'cheque', PaymentModeType::Cheque, true],
            ['Online Transfer', 'online_transfer', PaymentModeType::BankTransfer, true],
            ['Setoff', 'setoff', PaymentModeType::Setoff, false],
        ] as $i => [$name, $code, $type, $ref]) {
            // updateOrCreate: Inventory's migrations already insert online_transfer.
            $this->modes[$code] = PaymentMode::updateOrCreate(['code' => $code], [
                'payment_mode_name' => $name, 'type' => $type,
                'requires_reference_no' => $ref, 'sort_order' => $i + 1, 'is_active' => true,
            ]);
        }
    }

    private function modeId(string $code): int
    {
        return $this->modes[$code]->id;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed> $overrides  shallow: 'allocations' replaces the list
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'request_id'          => (string) Str::uuid(),
            'supplier_id'         => $this->supplier->id,
            'company_id'          => $this->companyA,
            'payment_date'        => '2026-10-26',
            'reference_no'        => null,
            'pay_from_account_id' => $this->cash->id,
            'payment_mode_id'     => $this->modeId('cash'),
            'description'         => 'October utilities',
            'allocations'         => [],
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function createPayment(array $overrides = []): BillPayment
    {
        $id = $this->actingAs($this->user)
            ->postJson('/api/v1/bill-payments', $this->payload($overrides))
            ->assertCreated()
            ->json('data.id');

        return BillPayment::findOrFail($id);
    }

    private function confirm(BillPayment $payment): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->postJson("/api/v1/bill-payments/{$payment->id}/confirm");
    }

    /** @return list<array{bill_id:int, amount:string}> */
    private function pay(Bill $bill, string $amount): array
    {
        return [['bill_id' => $bill->id, 'amount' => $amount]];
    }

    // ── Open bills ───────────────────────────────────────────────────────────

    public function test_open_bills_lists_unpaid_confirmed_bills_oldest_due_first(): void
    {
        $later   = $this->confirmedBill(['document_no' => 'CEB-2', 'due_date' => '2026-11-30']);
        $earlier = $this->confirmedBill(['document_no' => 'CEB-1', 'due_date' => '2026-10-25']);
        $this->createBill(['document_no' => 'CEB-DRAFT']);

        $rows = $this->actingAs($this->user)
            ->getJson("/api/v1/bill-payments/open-bills?supplier_id={$this->supplier->id}&company_id={$this->companyA}")
            ->assertOk()
            ->json('data');

        $this->assertSame([$earlier->id, $later->id], array_column($rows, 'bill_id'));
        $this->assertSame('15000.50', $rows[0]['balance']);
        $this->assertSame(1, $rows[0]['days_overdue']);
    }

    public function test_open_bills_for_a_company_the_user_cannot_access_is_404(): void
    {
        app(CompanyAccessService::class)->sync($this->user, [$this->companyA]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/bill-payments/open-bills?supplier_id={$this->supplier->id}&company_id={$this->companyB}")
            ->assertNotFound();
    }

    // ── Create ───────────────────────────────────────────────────────────────

    public function test_a_payment_is_created_as_a_draft_and_reserves_nothing(): void
    {
        $bill    = $this->confirmedBill();
        $payment = $this->createPayment(['allocations' => $this->pay($bill, '5000.00')]);

        $this->assertSame('BP-2026-0001', $payment->payment_no);
        $this->assertSame(BillPaymentStatus::Draft, $payment->status);
        $this->assertSame('5000.00', (string) $payment->total_amount);
        $this->assertSame('0.00', (string) $bill->fresh()->paid_amount);
        $this->assertSame(0, JournalEntry::forSource(PostingRole::SOURCE_BILL_PAYMENT, $payment->id)->count());
    }

    public function test_description_is_optional(): void
    {
        $bill    = $this->confirmedBill();
        $payment = $this->createPayment(['description' => '', 'allocations' => $this->pay($bill, '100.00')]);

        $this->assertNull($payment->description);
        $this->confirm($payment)->assertOk();
    }

    public function test_server_owned_fields_cannot_be_sent(): void
    {
        $bill    = $this->confirmedBill();
        $payment = $this->createPayment([
            'allocations'  => $this->pay($bill, '100.00'),
            'status'       => 'confirmed',
            'total_amount' => '999999.00',
            'payment_no'   => 'BP-HACK',
        ]);

        $this->assertSame(BillPaymentStatus::Draft, $payment->status);
        $this->assertSame('100.00', (string) $payment->total_amount);
        $this->assertSame('BP-2026-0001', $payment->payment_no);
    }

    public function test_a_retried_create_returns_the_same_payment(): void
    {
        $bill    = $this->confirmedBill();
        $payload = $this->payload(['allocations' => $this->pay($bill, '100.00')]);

        $first  = $this->actingAs($this->user)->postJson('/api/v1/bill-payments', $payload)->json('data.id');
        $second = $this->actingAs($this->user)->postJson('/api/v1/bill-payments', $payload)->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, BillPayment::count());
    }

    public function test_cannot_pay_more_than_is_owed(): void
    {
        $bill = $this->confirmedBill();

        $this->actingAs($this->user)
            ->postJson('/api/v1/bill-payments', $this->payload(['allocations' => $this->pay($bill, '15000.51')]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allocations.0.amount');
    }

    public function test_only_confirmed_bills_of_this_supplier_and_company_can_be_paid(): void
    {
        $draft = $this->createBill(['document_no' => 'D-1']);

        $other = SupplierMaster::create([
            'supplier_code' => 'SUP-0902', 'supplier_name' => 'Dialog', 'credit_period' => 0,
            'supplier_group_id' => $this->supplier->supplier_group_id,
        ]);
        $foreign = $this->confirmedBill(['supplier_id' => $other->id, 'document_no' => 'DLG-1']);
        $companyB = $this->confirmedBill(['company_id' => $this->companyB, 'document_no' => 'B-1']);

        foreach ([$draft, $foreign, $companyB] as $bill) {
            $this->actingAs($this->user)
                ->postJson('/api/v1/bill-payments', $this->payload(['allocations' => $this->pay($bill, '10.00')]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('allocations.0.bill_id');
        }
    }

    public function test_amounts_must_be_positive_with_two_decimals(): void
    {
        $bill = $this->confirmedBill();

        foreach (['0', '-5', '10.555'] as $amount) {
            $this->actingAs($this->user)
                ->postJson('/api/v1/bill-payments', $this->payload(['allocations' => $this->pay($bill, $amount)]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('allocations.0.amount');
        }
    }

    public function test_payment_date_cannot_be_future_or_in_a_closed_period(): void
    {
        $bill = $this->confirmedBill();

        $this->actingAs($this->user)
            ->postJson('/api/v1/bill-payments', $this->payload([
                'payment_date' => '2026-10-27', 'allocations' => $this->pay($bill, '10.00'),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_date');

        app(FinanceControlService::class)->setLockDate('2026-10-25');

        $this->actingAs($this->user)
            ->postJson('/api/v1/bill-payments', $this->payload([
                'payment_date' => '2026-10-25', 'allocations' => $this->pay($bill, '10.00'),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_date');
    }

    // ── Pay-from account and method ──────────────────────────────────────────

    public function test_the_mode_type_decides_the_pay_from_account(): void
    {
        $bill  = $this->confirmedBill();
        $alloc = $this->pay($bill, '10.00');

        // Transfer or cheque from a cash book, cash from a bank.
        foreach ([
            [$this->cash->id, 'online_transfer'],
            [$this->cash->id, 'cheque'],
            [$this->bank->id, 'cash'],
        ] as [$account, $code]) {
            $this->actingAs($this->user)
                ->postJson('/api/v1/bill-payments', $this->payload([
                    'pay_from_account_id' => $account, 'payment_mode_id' => $this->modeId($code),
                    'reference_no' => 'R1', 'cheque_no' => '000123', 'cheque_date' => '2026-10-26', 'allocations' => $alloc,
                ]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('pay_from_account_id');
        }
    }

    public function test_setoff_inactive_or_unknown_modes_are_refused(): void
    {
        $bill     = $this->confirmedBill();
        $inactive = PaymentMode::create(['payment_mode_name' => 'Old', 'code' => 'old', 'type' => PaymentModeType::Cash, 'is_active' => false]);

        foreach ([$this->modeId('setoff'), $inactive->id, 999999] as $modeId) {
            $this->actingAs($this->user)
                ->postJson('/api/v1/bill-payments', $this->payload([
                    'payment_mode_id' => $modeId, 'allocations' => $this->pay($bill, '10.00'),
                ]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('payment_mode_id');
        }
    }

    public function test_a_client_added_mode_behaves_by_its_type_not_its_code(): void
    {
        $bill = $this->confirmedBill();
        $ceft = PaymentMode::create([
            'payment_mode_name' => 'CEFT', 'code' => 'ceft', 'type' => PaymentModeType::BankTransfer, 'is_active' => true,
        ]);

        // A transfer type: the bank reference is required, and a cash book is refused.
        $this->actingAs($this->user)
            ->postJson('/api/v1/bill-payments', $this->payload([
                'payment_mode_id' => $ceft->id, 'allocations' => $this->pay($bill, '10.00'),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reference_no');

        $this->actingAs($this->user)
            ->postJson('/api/v1/bill-payments', $this->payload([
                'payment_mode_id' => $ceft->id, 'reference_no' => 'CEFT-55', 'allocations' => $this->pay($bill, '10.00'),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pay_from_account_id');

        $payment = $this->createPayment([
            'payment_mode_id' => $ceft->id, 'pay_from_account_id' => $this->bank->id, 'reference_no' => 'CEFT-55',
            'allocations' => $this->pay($bill, '10.00'),
        ]);
        $this->confirm($payment)->assertOk();

        $this->assertSame('CEFT', $payment->fresh()->payment_mode_name);
        $credit = JournalEntry::with('lines')->forSource(PostingRole::SOURCE_BILL_PAYMENT, $payment->id)->firstOrFail()
            ->lines->firstWhere('ledger_account_id', $this->bank->id);
        $this->assertSame('CEFT (ref CEFT-55)', $credit->description);
    }

    public function test_renaming_a_mode_later_does_not_change_the_payment(): void
    {
        $bill    = $this->confirmedBill();
        $payment = $this->createPayment(['allocations' => $this->pay($bill, '10.00')]);
        $this->confirm($payment)->assertOk();

        $this->modes['cash']->update(['payment_mode_name' => 'Cash Payment']);

        $this->assertSame('Cash', $payment->fresh()->payment_mode_name);
        $this->actingAs($this->user)->getJson("/api/v1/bill-payments/{$payment->id}")
            ->assertJsonPath('data.payment_mode_name', 'Cash');
    }

    public function test_a_mode_deactivated_after_the_draft_blocks_confirm(): void
    {
        $bill    = $this->confirmedBill();
        $payment = $this->createPayment(['allocations' => $this->pay($bill, '10.00')]);

        $this->modes['cash']->update(['is_active' => false]);

        $this->confirm($payment)->assertUnprocessable()->assertJsonValidationErrors('payment_mode_id');
        $this->assertSame(BillPaymentStatus::Draft, $payment->fresh()->status);
    }

    public function test_a_mode_whose_type_changed_after_the_draft_rechecks_the_reference_at_confirm(): void
    {
        $visa = PaymentMode::create([
            'payment_mode_name' => 'Visa POS', 'code' => 'visa_pos', 'type' => PaymentModeType::Card,
            'requires_reference_no' => false, 'sort_order' => 9, 'is_active' => true,
        ]);
        $bill    = $this->confirmedBill();
        $payload = $this->payload([
            'pay_from_account_id' => $this->bank->id, 'payment_mode_id' => $visa->id,
            'allocations' => $this->pay($bill, '10.00'),
        ]);
        $draft = $this->createPayment($payload);

        // Someone re-types the client's mode in Master Data.
        $visa->update(['type' => PaymentModeType::BankTransfer]);

        $this->confirm($draft)
            ->assertUnprocessable()
            ->assertJsonPath('errors.reference_no.0', 'The payment mode Visa POS now needs a Reference No. Edit the payment and enter it.');
        $this->assertSame(BillPaymentStatus::Draft, $draft->fresh()->status);

        $this->actingAs($this->user)
            ->putJson("/api/v1/bill-payments/{$draft->id}", [...$payload, 'reference_no' => 'TRF-9001'])
            ->assertOk();

        $this->confirm($draft)->assertOk();
    }

    public function test_options_list_usable_modes_with_their_rules(): void
    {
        $modes = collect($this->actingAs($this->user)->getJson('/api/v1/bill-payments/options')
            ->assertOk()->json('data.payment_modes'))->keyBy('name');

        $this->assertFalse($modes->has('Setoff'));
        $this->assertSame('cash_book', $modes['Cash']['pays_from']);
        $this->assertSame('bank', $modes['Cheque']['pays_from']);
        $this->assertTrue($modes['Cheque']['is_cheque']);
        $this->assertTrue($modes['Online Transfer']['needs_reference']);
    }

    public function test_an_expense_or_other_company_account_cannot_be_paid_from(): void
    {
        $bill      = $this->confirmedBill();
        $otherCash = $this->ledger('Beta Cash', 'Cash & Cash Equivalents', CashBookType::CashBook, $this->companyB);

        foreach ([$this->expense->id, $otherCash->id] as $account) {
            $this->actingAs($this->user)
                ->postJson('/api/v1/bill-payments', $this->payload([
                    'pay_from_account_id' => $account, 'allocations' => $this->pay($bill, '10.00'),
                ]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('pay_from_account_id');
        }
    }

    public function test_a_cheque_is_drawn_on_a_bank_needs_six_digits_and_a_date_and_is_used_once(): void
    {
        $bill = $this->confirmedBill();

        $this->actingAs($this->user)
            ->postJson('/api/v1/bill-payments', $this->payload([
                'pay_from_account_id' => $this->bank->id, 'payment_mode_id' => $this->modeId('cheque'),
                'allocations' => $this->pay($bill, '10.00'),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cheque_no', 'cheque_date']);

        foreach (['12345', '1234567', 'AB1234'] as $bad) {
            $this->actingAs($this->user)
                ->postJson('/api/v1/bill-payments', $this->payload([
                    'pay_from_account_id' => $this->bank->id, 'payment_mode_id' => $this->modeId('cheque'),
                    'cheque_no' => $bad, 'cheque_date' => '2026-10-26', 'allocations' => $this->pay($bill, '10.00'),
                ]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('cheque_no');
        }

        $cheque = [
            'pay_from_account_id' => $this->bank->id, 'payment_mode_id' => $this->modeId('cheque'),
            'cheque_no' => '000123', 'cheque_date' => '2026-11-10',
        ];

        $first = $this->createPayment([...$cheque, 'allocations' => $this->pay($bill, '10.00')]);
        $this->confirm($first)->assertOk();

        $this->actingAs($this->user)
            ->postJson('/api/v1/bill-payments', $this->payload([
                ...$cheque, 'cheque_no' => ' 000123 ', 'allocations' => $this->pay($bill, '10.00'),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cheque_no');

        // Voiding the first payment frees the cheque number.
        $this->actingAs($this->user)
            ->postJson("/api/v1/bill-payments/{$first->id}/void", ['reason' => 'Cheque spoiled', 'reversal_date' => '2026-10-26'])
            ->assertOk();

        $this->createPayment([...$cheque, 'allocations' => $this->pay($bill, '10.00')]);
    }

    /** Inserts a Supplier Payment cheque line the way Inventory stores one. */
    private function inventoryChequePayment(string $chequeNo, string $status = 'confirmed'): int
    {
        $paymentId = (int) DB::table('inv_supplier_payments')->insertGetId([
            'payment_no' => 'PAY-0777', 'payment_date' => '2026-10-20', 'supplier_id' => $this->supplier->id,
            'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('inv_supplier_payment_settlements')->insert([
            'payment_id' => $paymentId, 'payment_mode_id' => $this->modeId('cheque'),
            'payment_mode_code' => 'cheque', 'payment_mode_name' => 'Cheque', 'amount' => 500,
            'reference_no' => $chequeNo, 'instrument_date' => '2026-10-20',
            'ledger_account_id' => $this->bank->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $paymentId;
    }

    public function test_a_cheque_already_used_on_an_inventory_supplier_payment_is_refused(): void
    {
        $bill      = $this->confirmedBill();
        $paymentId = $this->inventoryChequePayment('000555');

        $cheque = [
            'pay_from_account_id' => $this->bank->id, 'payment_mode_id' => $this->modeId('cheque'),
            'cheque_no' => '000555', 'cheque_date' => '2026-10-26', 'allocations' => $this->pay($bill, '10.00'),
        ];

        $this->actingAs($this->user)->postJson('/api/v1/bill-payments', $this->payload($cheque))
            ->assertUnprocessable()
            ->assertJsonPath('errors.cheque_no.0', 'Cheque 000555 is already used on PAY-0777.');

        // Voiding the Supplier Payment frees the leaf.
        DB::table('inv_supplier_payments')->where('id', $paymentId)->update(['status' => 'voided']);
        $this->createPayment($cheque);
    }

    public function test_a_supplier_payment_cheque_entered_after_the_draft_blocks_its_confirm(): void
    {
        $bill  = $this->confirmedBill();
        $draft = $this->createPayment([
            'pay_from_account_id' => $this->bank->id, 'payment_mode_id' => $this->modeId('cheque'),
            'cheque_no' => '000556', 'cheque_date' => '2026-10-26', 'allocations' => $this->pay($bill, '10.00'),
        ]);

        $this->inventoryChequePayment('000556');

        $this->confirm($draft)->assertUnprocessable()->assertJsonValidationErrors('cheque_no');
        $this->assertSame(BillPaymentStatus::Draft, $draft->fresh()->status);
    }

    public function test_an_inventory_supplier_payment_cannot_reuse_a_bill_payment_cheque(): void
    {
        Permission::firstOrCreate(['name' => 'create_supplier_payments', 'guard_name' => 'web']);
        app(SettingsService::class)->set('module.inventory', true);
        $this->user->givePermissionTo('create_supplier_payments');

        $bill = $this->confirmedBill();
        $this->createPayment([
            'pay_from_account_id' => $this->bank->id, 'payment_mode_id' => $this->modeId('cheque'),
            'cheque_no' => '000557', 'cheque_date' => '2026-10-26', 'allocations' => $this->pay($bill, '10.00'),
        ]);

        $settlement = [
            'payment_mode_id' => $this->modeId('cheque'), 'amount' => 10, 'reference_no' => '000557',
            'instrument_date' => '2026-10-26', 'ledger_account_id' => $this->bank->id,
        ];

        $this->actingAs($this->user)->postJson('/api/v1/supplier-payments', ['settlements' => [$settlement]])
            ->assertUnprocessable()
            ->assertJsonPath('errors', fn (array $e): bool => str_contains(
                $e['settlements.0.reference_no'][0] ?? '', 'already used on BP-',
            ));

        // A different bank account has its own cheque book.
        $otherBank = $this->ledger('BOC Savings', 'Cash & Cash Equivalents', CashBookType::Bank, $this->companyA);
        $this->actingAs($this->user)
            ->postJson('/api/v1/supplier-payments', ['settlements' => [[...$settlement, 'ledger_account_id' => $otherBank->id]]])
            ->assertJsonMissingValidationErrors('settlements.0.reference_no');
    }

    public function test_a_payment_cannot_be_dated_before_the_bill(): void
    {
        $bill = $this->confirmedBill(['transaction_date' => '2026-10-20', 'document_date' => '2026-10-18']);

        $this->actingAs($this->user)
            ->postJson('/api/v1/bill-payments', $this->payload([
                'payment_date' => '2026-10-19', 'allocations' => $this->pay($bill, '10.00'),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allocations.0.bill_id');

        // The same day as the bill is fine.
        $draft = $this->createPayment(['payment_date' => '2026-10-20', 'allocations' => $this->pay($bill, '10.00')]);

        // Re-checked at confirm, under lock.
        DB::table('fin_bills')->where('id', $bill->id)->update(['transaction_date' => '2026-10-21']);
        $this->confirm($draft)->assertUnprocessable()->assertJsonValidationErrors('allocations');
    }

    public function test_a_create_that_loses_the_request_id_race_returns_the_winner(): void
    {
        $bill   = $this->confirmedBill();
        $winner = $this->createPayment(['allocations' => $this->pay($bill, '10.00')]);
        $retry  = (string) Str::uuid();

        // Simulate the race: the twin request commits right after this one's
        // "already made?" lookup came back empty, so the insert hits the
        // request_id unique index.
        $fired = false;
        DB::listen(function ($query) use (&$fired, $winner, $retry): void {
            if (! $fired && str_contains($query->sql, 'from "fin_bill_payments" where "request_id" = ?') && in_array($retry, $query->bindings, true)) {
                $fired = true;
                DB::table('fin_bill_payments')->where('id', $winner->id)->update(['request_id' => $retry]);
            }
        });

        $this->actingAs($this->user)
            ->postJson('/api/v1/bill-payments', $this->payload(['request_id' => $retry, 'allocations' => $this->pay($bill, '10.00')]))
            ->assertCreated()
            ->assertJsonPath('data.id', $winner->id);

        $this->assertTrue($fired);
        $this->assertSame(1, BillPayment::where('request_id', $retry)->count());
    }

    public function test_cheque_fields_are_dropped_on_a_cash_payment(): void
    {
        $bill    = $this->confirmedBill();
        $payment = $this->createPayment([
            'cheque_no' => '999', 'cheque_date' => '2026-10-26', 'allocations' => $this->pay($bill, '10.00'),
        ]);

        $this->assertNull($payment->cheque_no);
        $this->assertNull($payment->cheque_date);
    }

    // ── Confirm ──────────────────────────────────────────────────────────────

    public function test_confirming_posts_ap_per_bill_against_cash_and_updates_the_bills(): void
    {
        $billA = $this->confirmedBill(['document_no' => 'CEB-1']);
        $billB = $this->confirmedBill(['document_no' => 'CEB-2', 'lines' => [[
            'ledger_account_id' => $this->expense->id, 'amount' => '2000.00',
        ]]]);

        $payment = $this->createPayment([
            'pay_from_account_id' => $this->bank->id, 'payment_mode_id' => $this->modeId('online_transfer'), 'reference_no' => 'TRF-88',
            'allocations' => [
                ['bill_id' => $billA->id, 'amount' => '5000.50'],
                ['bill_id' => $billB->id, 'amount' => '2000.00'],
            ],
        ]);

        $this->confirm($payment)->assertOk()->assertJsonPath('data.status', 'confirmed');

        $entry = JournalEntry::with('lines')->forSource(PostingRole::SOURCE_BILL_PAYMENT, $payment->id)->firstOrFail();
        $this->assertTrue($entry->isBalanced());
        $this->assertSame('2026-10-26', $entry->journal_date->toDateString());
        $this->assertSame($this->companyA, $entry->company_id);
        $this->assertCount(3, $entry->lines);

        $ap = $this->mapped(AccountRole::AccountsPayable);
        $debitA = $entry->lines->first(fn ($l) => $l->source_type === PostingRole::SOURCE_BILL && $l->source_id === $billA->id);
        $this->assertSame($ap, $debitA->ledger_account_id);
        $this->assertSame('5000.5000', $debitA->debit);
        $this->assertSame($this->supplier->id, $debitA->party_id);

        $credit = $entry->lines->firstWhere('ledger_account_id', $this->bank->id);
        $this->assertSame('7000.5000', $credit->credit);

        $billA->refresh();
        $billB->refresh();
        $this->assertSame('5000.50', (string) $billA->paid_amount);
        $this->assertSame(PaymentStatus::PartlyPaid, $billA->payment_status);
        $this->assertSame('10000.00', $billA->balance());
        $this->assertSame(PaymentStatus::Paid, $billB->payment_status);

        $this->assertSame('15000.50', (string) $payment->allocations()->where('bill_id', $billA->id)->value('balance_before'));
        $this->assertTrue(AuditLog::where('auditable_type', 'bill')->where('auditable_id', $billA->id)->where('event', 'payment_applied')->exists());
    }

    public function test_a_fully_paid_bill_leaves_the_open_bills_list(): void
    {
        $bill    = $this->confirmedBill();
        $payment = $this->createPayment(['allocations' => $this->pay($bill, '15000.50')]);
        $this->confirm($payment)->assertOk();

        $this->actingAs($this->user)
            ->getJson("/api/v1/bill-payments/open-bills?supplier_id={$this->supplier->id}&company_id={$this->companyA}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_two_drafts_cannot_both_pay_the_same_balance(): void
    {
        $bill   = $this->confirmedBill();
        $first  = $this->createPayment(['allocations' => $this->pay($bill, '15000.50')]);
        $second = $this->createPayment(['allocations' => $this->pay($bill, '15000.50')]);

        $this->confirm($first)->assertOk();
        $this->confirm($second)->assertUnprocessable()->assertJsonValidationErrors('allocations');

        $this->assertSame('15000.50', (string) $bill->fresh()->paid_amount);
    }

    public function test_confirming_twice_posts_once(): void
    {
        $bill    = $this->confirmedBill();
        $payment = $this->createPayment(['allocations' => $this->pay($bill, '100.00')]);

        $this->confirm($payment)->assertOk();
        $this->confirm($payment)->assertUnprocessable();

        $this->assertSame(1, JournalEntry::forSource(PostingRole::SOURCE_BILL_PAYMENT, $payment->id)->count());
        $this->assertSame('100.00', (string) $bill->fresh()->paid_amount);
    }

    public function test_maker_checker_blocks_the_maker_from_confirming(): void
    {
        $bill    = $this->confirmedBill();
        $payment = $this->createPayment(['allocations' => $this->pay($bill, '100.00')]);

        app(FinanceControlService::class)->setMakerChecker(true);

        $this->confirm($payment)->assertUnprocessable();

        $this->actingAs($this->makeUser())
            ->postJson("/api/v1/bill-payments/{$payment->id}/confirm")
            ->assertOk();
    }

    public function test_a_deactivated_pay_from_account_blocks_confirm(): void
    {
        $bill    = $this->confirmedBill();
        $payment = $this->createPayment(['allocations' => $this->pay($bill, '100.00')]);

        $this->cash->forceFill(['is_active' => false])->save();

        $this->confirm($payment)->assertUnprocessable()->assertJsonValidationErrors('pay_from_account_id');
    }

    public function test_a_paid_bill_cannot_be_voided_until_the_payment_is(): void
    {
        $bill    = $this->confirmedBill();
        $payment = $this->createPayment(['allocations' => $this->pay($bill, '100.00')]);
        $this->confirm($payment)->assertOk();

        $this->actingAs($this->user)
            ->postJson("/api/v1/bills/{$bill->id}/void", ['reason' => 'Wrong', 'reversal_date' => '2026-10-26'])
            ->assertUnprocessable();

        $this->actingAs($this->user)
            ->postJson("/api/v1/bill-payments/{$payment->id}/void", ['reason' => 'Wrong', 'reversal_date' => '2026-10-26'])
            ->assertOk();

        $this->actingAs($this->user)
            ->postJson("/api/v1/bills/{$bill->id}/void", ['reason' => 'Wrong', 'reversal_date' => '2026-10-26'])
            ->assertOk();
    }

    // ── Void ─────────────────────────────────────────────────────────────────

    public function test_voiding_reverses_on_the_chosen_date_and_restores_the_bills(): void
    {
        $bill    = $this->confirmedBill();
        $payment = $this->createPayment(['payment_date' => '2026-10-21', 'allocations' => $this->pay($bill, '15000.50')]);
        $this->confirm($payment)->assertOk();

        $this->actingAs($this->user)
            ->postJson("/api/v1/bill-payments/{$payment->id}/void", ['reason' => 'Bounced', 'reversal_date' => '2026-10-23'])
            ->assertOk()
            ->assertJsonPath('data.status', 'voided');

        $original = JournalEntry::forSource(PostingRole::SOURCE_BILL_PAYMENT, $payment->id)->firstOrFail();
        $reversal = JournalEntry::findOrFail($original->reversed_by_journal_id);
        $this->assertSame('2026-10-23', $reversal->journal_date->toDateString());

        $bill->refresh();
        $this->assertSame('0.00', (string) $bill->paid_amount);
        $this->assertSame(PaymentStatus::Unpaid, $bill->payment_status);
    }

    public function test_void_reversal_date_rules(): void
    {
        $bill    = $this->confirmedBill();
        $payment = $this->createPayment(['payment_date' => '2026-10-22', 'allocations' => $this->pay($bill, '100.00')]);
        $this->confirm($payment)->assertOk();

        app(FinanceControlService::class)->setLockDate('2026-10-23');

        foreach (['2026-10-21', '2026-10-23', '2026-10-27'] as $date) {
            $this->actingAs($this->user)
                ->postJson("/api/v1/bill-payments/{$payment->id}/void", ['reason' => 'Test', 'reversal_date' => $date])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('reversal_date');
        }

        $this->assertSame(BillPaymentStatus::Confirmed, $payment->fresh()->status);
    }

    public function test_only_confirmed_payments_can_be_voided(): void
    {
        $bill    = $this->confirmedBill();
        $payment = $this->createPayment(['allocations' => $this->pay($bill, '100.00')]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/bill-payments/{$payment->id}/void", ['reason' => 'Test', 'reversal_date' => '2026-10-26'])
            ->assertUnprocessable();
    }

    // ── Edit / delete ────────────────────────────────────────────────────────

    public function test_a_draft_can_be_edited_but_a_stale_copy_is_refused(): void
    {
        $bill    = $this->confirmedBill();
        $payment = $this->createPayment(['allocations' => $this->pay($bill, '100.00')]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/bill-payments/{$payment->id}", $this->payload([
                'allocations' => $this->pay($bill, '250.00'),
                'updated_at'  => $payment->updated_at->toISOString(),
            ]))
            ->assertOk()
            ->assertJsonPath('data.total_amount', '250.00');

        $this->actingAs($this->user)
            ->putJson("/api/v1/bill-payments/{$payment->id}", $this->payload([
                'allocations' => $this->pay($bill, '300.00'),
                'updated_at'  => '2026-01-01T00:00:00Z',
            ]))
            ->assertStatus(409);
    }

    public function test_confirmed_payments_cannot_be_edited_or_deleted(): void
    {
        $bill    = $this->confirmedBill();
        $payment = $this->createPayment(['allocations' => $this->pay($bill, '100.00')]);
        $this->confirm($payment)->assertOk();

        $this->actingAs($this->user)
            ->putJson("/api/v1/bill-payments/{$payment->id}", $this->payload(['allocations' => $this->pay($bill, '50.00')]))
            ->assertUnprocessable();

        $this->actingAs($this->user)->deleteJson("/api/v1/bill-payments/{$payment->id}")->assertUnprocessable();
    }

    public function test_a_draft_can_be_deleted(): void
    {
        $bill    = $this->confirmedBill();
        $payment = $this->createPayment(['allocations' => $this->pay($bill, '100.00')]);

        $this->actingAs($this->user)->deleteJson("/api/v1/bill-payments/{$payment->id}")->assertNoContent();

        $this->assertSoftDeleted('fin_bill_payments', ['id' => $payment->id]);
    }

    // ── Access ───────────────────────────────────────────────────────────────

    public function test_each_action_needs_its_permission(): void
    {
        $bill    = $this->confirmedBill();
        $payment = $this->createPayment(['allocations' => $this->pay($bill, '100.00')]);
        $viewer  = $this->makeUser(['view_bill_payments']);

        $this->actingAs($viewer)->getJson('/api/v1/bill-payments')->assertOk();
        $this->actingAs($viewer)->postJson('/api/v1/bill-payments', $this->payload(['allocations' => $this->pay($bill, '1.00')]))->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/v1/bill-payments/{$payment->id}/confirm")->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/api/v1/bill-payments/{$payment->id}")->assertForbidden();

        $this->actingAs($this->makeUser(['view_bills']))->getJson('/api/v1/bill-payments')->assertForbidden();
    }

    public function test_another_companys_payment_is_404_and_hidden_from_the_list(): void
    {
        $bill    = $this->confirmedBill();
        $payment = $this->createPayment(['allocations' => $this->pay($bill, '100.00')]);

        $restricted = $this->makeUser();
        app(CompanyAccessService::class)->sync($restricted, [$this->companyB]);

        $this->actingAs($restricted)->getJson("/api/v1/bill-payments/{$payment->id}")->assertNotFound();
        $this->actingAs($restricted)->postJson("/api/v1/bill-payments/{$payment->id}/confirm")->assertNotFound();
        $this->actingAs($restricted)->getJson('/api/v1/bill-payments')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_finance_disabled_blocks_the_endpoints(): void
    {
        app(SettingsService::class)->set('module.finance', false);

        $this->actingAs($this->user)->getJson('/api/v1/bill-payments')->assertForbidden();
    }

    // ── Bill view and attachments ────────────────────────────────────────────

    public function test_the_bill_lists_its_payments(): void
    {
        $bill    = $this->confirmedBill();
        $payment = $this->createPayment(['allocations' => $this->pay($bill, '100.00')]);
        $this->confirm($payment)->assertOk();

        $this->actingAs($this->user)
            ->getJson("/api/v1/bills/{$bill->id}")
            ->assertOk()
            ->assertJsonPath('data.payments.0.payment_no', 'BP-2026-0001')
            ->assertJsonPath('data.payments.0.amount', '100.00')
            ->assertJsonPath('data.balance', '14900.50');
    }

    public function test_files_can_be_attached_to_a_payment(): void
    {
        Storage::fake(AttachmentService::DISK);

        $bill    = $this->confirmedBill();
        $payment = $this->createPayment(['allocations' => $this->pay($bill, '100.00')]);
        $file    = UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");

        $this->actingAs($this->user)
            ->post("/api/v1/finance-attachments/bill_payment/{$payment->id}", ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->actingAs($this->user)
            ->getJson("/api/v1/finance-attachments/bill_payment/{$payment->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
