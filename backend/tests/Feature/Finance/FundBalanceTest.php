<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Contracts\JournalPoster;
use App\Enums\PaymentModeType;
use App\Models\PaymentMode;
use App\Support\Journal\JournalLineRequest;
use App\Support\Journal\JournalRequest;
use App\Support\Journal\PostingRole;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Finance\Enums\BillPaymentStatus;
use Modules\Finance\Enums\CashBookType;
use Modules\Finance\Models\BillPayment;
use Modules\Finance\Models\JournalEntry;
use Modules\Finance\Models\LedgerAccount;
use Tests\Feature\Finance\Concerns\BillFixtures;
use Tests\TestCase;

/**
 * "Is there enough money?" on a payment (client decision):
 *   Cash Book short -> refused, whatever the user says.
 *   Bank short      -> warned; confirmed again with allow_overdraw it goes through.
 * Plus the balances payment screens and the Ledger Accounts list show.
 *
 * Driven through Bill Payment; SupplierPaymentPostingTest proves the same
 * guard reaches Inventory's Supplier Payment.
 */
class FundBalanceTest extends TestCase
{
    use BillFixtures;
    use RefreshDatabase;

    private LedgerAccount $cash;
    private LedgerAccount $bank;

    /** @var array<string, PaymentMode> */
    private array $modes = [];

    private int $fundingSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->billPermissions = [
            ...$this->billPermissions,
            'view_bill_payments', 'create_bill_payments', 'confirm_bill_payments', 'view_ledger_accounts',
        ];

        $this->setUpBills();

        $this->cash = $this->ledger('Main Cash', 'Cash & Cash Equivalents', CashBookType::CashBook, $this->companyA);
        $this->bank = $this->ledger('Sampath Current', 'Cash & Cash Equivalents', CashBookType::Bank, $this->companyA);

        foreach ([
            ['Cash', 'cash', PaymentModeType::Cash, false],
            ['Online Transfer', 'online_transfer', PaymentModeType::BankTransfer, true],
        ] as $i => [$name, $code, $type, $ref]) {
            $this->modes[$code] = PaymentMode::updateOrCreate(['code' => $code], [
                'payment_mode_name' => $name, 'type' => $type,
                'requires_reference_no' => $ref, 'sort_order' => $i + 1, 'is_active' => true,
            ]);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Put money into an account: Dr the account / Cr Opening Balance Equity. */
    private function fund(LedgerAccount $account, float $amount, string $date = '2026-10-01'): void
    {
        app(JournalPoster::class)->post(new JournalRequest(
            sourceType:  'test_funding',
            sourceId:    ++$this->fundingSeq,
            journalDate: new DateTimeImmutable($date),
            lines:       [
                JournalLineRequest::debit($account->id, $amount),
                JournalLineRequest::credit(PostingRole::OPENING_BALANCE_EQUITY, $amount),
            ],
            companyId:   $this->companyA,
        ));
    }

    /** A draft payment of the whole 15,000.50 bill from the given account. */
    private function draftPayment(LedgerAccount $from): BillPayment
    {
        $bill = $this->confirmedBill();
        $bank = $from->cash_book_type === CashBookType::Bank;

        $id = $this->actingAs($this->user)->postJson('/api/v1/bill-payments', [
            'request_id'          => (string) Str::uuid(),
            'supplier_id'         => $this->supplier->id,
            'company_id'          => $this->companyA,
            'payment_date'        => '2026-10-26',
            'reference_no'        => $bank ? 'TRF-1' : null,
            'pay_from_account_id' => $from->id,
            'payment_mode_id'     => $this->modes[$bank ? 'online_transfer' : 'cash']->id,
            'allocations'         => [['bill_id' => $bill->id, 'amount' => '15000.50']],
        ])->assertCreated()->json('data.id');

        return BillPayment::findOrFail($id);
    }

    private function confirm(BillPayment $payment, bool $allowOverdraw = false): TestResponse
    {
        return $this->actingAs($this->user)->postJson(
            "/api/v1/bill-payments/{$payment->id}/confirm",
            $allowOverdraw ? ['allow_overdraw' => true] : [],
        );
    }

    // ── Cash book: blocked ───────────────────────────────────────────────────

    public function test_paying_more_cash_than_the_cash_book_holds_is_refused(): void
    {
        $this->fund($this->cash, 10000);
        $payment = $this->draftPayment($this->cash);

        $this->confirm($payment)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pay_from')
            ->assertJsonPath('errors.pay_from.0', 'Only 10,000.00 is available in Main Cash on 26-10-2026. This payment needs 15,000.50.');

        $this->assertSame(BillPaymentStatus::Draft, $payment->fresh()->status);
        $this->assertNull(JournalEntry::forSource(PostingRole::SOURCE_BILL_PAYMENT, $payment->id)->first());
    }

    public function test_saying_yes_to_the_bank_warning_never_lets_cash_go_negative(): void
    {
        $payment = $this->draftPayment($this->cash);

        $this->confirm($payment, allowOverdraw: true)->assertUnprocessable()->assertJsonValidationErrors('pay_from');
    }

    public function test_cash_that_covers_the_payment_confirms(): void
    {
        $this->fund($this->cash, 20000);

        $this->confirm($this->draftPayment($this->cash))->assertOk()->assertJsonPath('data.status', 'confirmed');
    }

    public function test_a_back_dated_payment_cannot_spend_cash_that_arrived_later(): void
    {
        // In the book now, but only from the 28th; the payment is dated the 26th.
        $this->fund($this->cash, 20000, date: '2026-10-28');

        $this->confirm($this->draftPayment($this->cash))
            ->assertUnprocessable()
            ->assertJsonPath('errors.pay_from.0', 'Only 0.00 is available in Main Cash on 26-10-2026. This payment needs 15,000.50.');
    }

    // ── Bank: warned, then allowed ───────────────────────────────────────────

    public function test_a_bank_short_of_money_warns_and_goes_through_once_confirmed(): void
    {
        $this->fund($this->bank, 5000);
        $payment = $this->draftPayment($this->bank);

        $this->confirm($payment)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('bank_overdraw')
            ->assertJsonPath('errors.bank_overdraw.0', 'Sampath Current has 5,000.00 on 26-10-2026. Paying 15,000.50 will take it to -10,000.50.');

        $this->assertSame(BillPaymentStatus::Draft, $payment->fresh()->status);

        $this->confirm($payment, allowOverdraw: true)->assertOk()->assertJsonPath('data.status', 'confirmed');
    }

    public function test_a_bank_with_enough_money_does_not_warn(): void
    {
        $this->fund($this->bank, 50000);

        $this->confirm($this->draftPayment($this->bank))->assertOk();
    }

    // ── What the screens show ────────────────────────────────────────────────

    public function test_the_payment_screen_and_the_ledger_list_show_each_balance(): void
    {
        $this->fund($this->cash, 12000);
        $this->confirm($this->draftPayment($this->bank), allowOverdraw: true)->assertOk();

        $offered = collect($this->actingAs($this->user)->getJson('/api/v1/bill-payments/options')
            ->assertOk()->json('data.pay_from_accounts'))->keyBy('id');

        $this->assertSame('12000.00', $offered[$this->cash->id]['available']);
        $this->assertSame('-15000.50', $offered[$this->bank->id]['available']);

        $sources = collect($this->actingAs($this->user)->getJson('/api/v1/ledger-accounts/all?fund_sources_only=1')
            ->assertOk()->json('data'))->keyBy('id');

        $this->assertSame('12000.00', $sources[$this->cash->id]['available']);

        $listed = collect($this->actingAs($this->user)->getJson('/api/v1/ledger-accounts')
            ->assertOk()->json('data'))->keyBy('id');

        $this->assertSame('-15000.50', $listed[$this->bank->id]['balance']);
        // Only cash and bank rows carry a balance.
        $this->assertArrayNotHasKey('balance', $listed[$this->expense->id]);
    }
}
