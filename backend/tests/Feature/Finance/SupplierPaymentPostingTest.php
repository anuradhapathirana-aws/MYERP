<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\PaymentMode;
use App\Models\SupplierGroup;
use App\Models\SupplierMaster;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\Journal\PostingRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Database\Seeders\FinanceChartOfAccountsSeeder;
use Modules\Finance\Database\Seeders\FinanceGlAccountsSeeder;
use Modules\Finance\DTOs\LedgerAccountData;
use Modules\Finance\Enums\AccountRole;
use Modules\Finance\Enums\CashBookType;
use Modules\Finance\Enums\JournalStatus;
use Modules\Finance\Models\AccountDefault;
use Modules\Finance\Models\ControlAccount;
use Modules\Finance\Models\JournalEntry;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Services\JournalPostingService;
use Modules\Finance\Services\LedgerAccountService;
use Modules\Inventory\Models\GoodsReceivedNote;
use Modules\Inventory\Models\SupplierPayment;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A supplier payment reaching the general ledger.
 *
 * This is the join between the two modules, so it is also where the Golden Rule
 * is proved: the same confirm() call posts a journal when Finance is installed
 * and does nothing at all when it is not, with no branch in Inventory either way.
 */
class SupplierPaymentPostingTest extends TestCase
{
    use RefreshDatabase;

    private User           $user;
    private SupplierMaster $supplier;
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
            'view_supplier_payments', 'create_supplier_payments', 'edit_supplier_payments',
            'delete_supplier_payments', 'confirm_supplier_payments',
            // Separate from confirm on purpose: undoing something already in
            // the ledger is a different level of trust from entering it.
            'void_supplier_payments',
        ];

        foreach ($permissions as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['inventory', 'finance']]);
        $this->user->givePermissionTo($permissions);

        // The supplier-group backfill migration already creates this group, so
        // adopt it rather than colliding with its unique name.
        $group = SupplierGroup::firstOrCreate(
            ['name' => 'Trade (Local)'],
            ['code' => 'TRADE', 'is_active' => true],
        );

        $this->supplier = SupplierMaster::create([
            'supplier_code'     => 'SUP-0001',
            'supplier_name'     => 'ABC Textiles',
            'supplier_group_id' => $group->id,
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

    // ── The worked case from the design ──────────────────────────────────────

    /**
     * 100,000 owed. 5,000 written off as a settlement discount, 95,000 paid
     * from two different bank accounts of the same company.
     */
    public function test_a_payment_with_a_discount_and_two_banks_posts_correctly(): void
    {
        $grn = $this->confirmedGrn(100000);

        $payment = $this->confirmPayment([
            'allocations' => [[
                'reference_type' => 'grn',
                'reference_id'   => $grn->id,
                'payment_amount' => 95000,
                'discount'       => 5000,
            ]],
            'settlements' => [
                $this->settlement($this->bankA, 60000, '123456'),
                $this->settlement($this->bankB, 35000, '654321'),
            ],
        ]);

        $entry = JournalEntry::with('lines')->forSource(PostingRole::SOURCE_SUPPLIER_PAYMENT, $payment->id)->first();

        $this->assertNotNull($entry, 'Confirming the payment must write a journal.');
        $this->assertSame(JournalStatus::Posted, $entry->status);
        $this->assertTrue($entry->isBalanced());
        $this->assertSame('100000.0000', $entry->total_debit);
        $this->assertSame('100000.0000', $entry->total_credit);

        // Company was never passed in -- derived from the chosen bank accounts.
        $this->assertSame(1, $entry->company_id);

        // Dr Accounts Payable, cleared by payment + discount exactly as
        // computeOutstanding() clears the GRN.
        $payable = $entry->lines->firstWhere('ledger_account_id', $this->mapped(AccountRole::AccountsPayable));
        $this->assertSame('100000.0000', $payable->debit);
        $this->assertSame(PostingRole::PARTY_SUPPLIER, $payable->party_type);
        $this->assertSame($this->supplier->id, $payable->party_id);
        $this->assertSame('grn', $payable->source_type);
        $this->assertSame($grn->id, $payable->source_id);

        // Cr each bank account for its own amount -- not one lumped line.
        $this->assertSame('60000.0000', $entry->lines->firstWhere('ledger_account_id', $this->bankA->id)->credit);
        $this->assertSame('35000.0000', $entry->lines->firstWhere('ledger_account_id', $this->bankB->id)->credit);

        // Cr Discount Received for the write-off.
        $discount = $entry->lines->firstWhere('ledger_account_id', $this->mapped(AccountRole::DiscountReceived));
        $this->assertSame('5000.0000', $discount->credit);

        $this->assertCount(4, $entry->lines);
    }

    public function test_each_bank_account_carries_only_its_own_money(): void
    {
        $grn = $this->confirmedGrn(100000);

        $this->confirmPayment([
            'allocations' => [[
                'reference_type' => 'grn', 'reference_id' => $grn->id,
                'payment_amount' => 100000, 'discount' => 0,
            ]],
            'settlements' => [
                $this->settlement($this->bankA, 70000, '111111'),
                $this->settlement($this->bankB, 30000, '222222'),
            ],
        ]);

        // This is what makes bank reconciliation possible: each account's GL
        // balance matches what actually left that one account.
        $this->assertSame(-70000.0, $this->balance($this->bankA->id));
        $this->assertSame(-30000.0, $this->balance($this->bankB->id));
    }

    public function test_payables_are_posted_per_grn_so_ageing_works(): void
    {
        $first  = $this->confirmedGrn(60000);
        $second = $this->confirmedGrn(40000);

        $payment = $this->confirmPayment([
            'allocations' => [
                ['reference_type' => 'grn', 'reference_id' => $first->id,  'payment_amount' => 60000, 'discount' => 0],
                ['reference_type' => 'grn', 'reference_id' => $second->id, 'payment_amount' => 40000, 'discount' => 0],
            ],
            'settlements' => [$this->settlement($this->bankA, 100000, '333333')],
        ]);

        $entry = JournalEntry::with('lines')->forSource(PostingRole::SOURCE_SUPPLIER_PAYMENT, $payment->id)->first();

        $payableLines = $entry->lines->where('ledger_account_id', $this->mapped(AccountRole::AccountsPayable));

        $this->assertCount(2, $payableLines, 'One payable line per GRN, not one lump.');
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            $payableLines->pluck('source_id')->all(),
        );
    }

    public function test_an_advance_payment_debits_supplier_advances(): void
    {
        $payment = $this->confirmPayment([
            'is_advance'     => true,
            'advance_amount' => 50000,
            'allocations'    => [],
            'settlements'    => [$this->settlement($this->bankA, 50000, '444444')],
        ]);

        $entry = JournalEntry::with('lines')->forSource(PostingRole::SOURCE_SUPPLIER_PAYMENT, $payment->id)->first();

        // Money prepaid is an asset we hold a claim on, not an expense.
        $advance = $entry->lines->firstWhere('ledger_account_id', $this->mapped(AccountRole::SupplierAdvance));
        $this->assertSame('50000.0000', $advance->debit);
        $this->assertSame($this->supplier->id, $advance->party_id);

        $this->assertSame('50000.0000', $entry->lines->firstWhere('ledger_account_id', $this->bankA->id)->credit);
        $this->assertTrue($entry->isBalanced());
    }

    /** The supplier group may name its own payables account; it wins over the global default. */
    public function test_a_supplier_group_override_is_honoured(): void
    {
        $other = LedgerAccount::firstWhere('ledger_account_name', 'Customer Advances'); // also a liability
        SupplierGroup::whereKey($this->supplier->supplier_group_id)
            ->update(['default_payable_account_id' => $other->id]);

        $grn = $this->confirmedGrn(10000);

        $payment = $this->confirmPayment([
            'allocations' => [['reference_type' => 'grn', 'reference_id' => $grn->id, 'payment_amount' => 10000, 'discount' => 0]],
            'settlements' => [$this->settlement($this->bankA, 10000, '555555')],
        ]);

        $entry = JournalEntry::with('lines')->forSource(PostingRole::SOURCE_SUPPLIER_PAYMENT, $payment->id)->first();

        $this->assertNotNull(
            $entry->lines->firstWhere('ledger_account_id', $other->id),
            'The group override must beat the global accounts_payable mapping.',
        );
    }

    // ── Failing loudly ──────────────────────────────────────────────────────

    public function test_confirming_without_a_pay_from_account_is_refused(): void
    {
        $grn = $this->confirmedGrn(10000);

        // Saved as a draft without a Pay From account, which is allowed: the
        // user may still be part way through the form.
        $id = $this->createPayment([
            'allocations' => [['reference_type' => 'grn', 'reference_id' => $grn->id, 'payment_amount' => 10000, 'discount' => 0]],
            'settlements' => [[
                'payment_mode_id' => $this->cheque->id,
                'amount'          => 10000,
                'reference_no'    => '666666',
                'instrument_date' => '2026-04-12',
                // no ledger_account_id
            ]],
        ]);

        // But confirming it must fail, because a journal cannot say where the
        // money went.
        $this->actingAs($this->user)
            ->postJson("/api/v1/supplier-payments/{$id}/confirm")
            ->assertStatus(422);

        $this->assertDatabaseCount('fin_journal_entries', 0);

        // And the whole confirm rolled back -- the payment is still a draft.
        $this->assertSame('draft', SupplierPayment::find($id)->status->value);
    }

    public function test_an_unmapped_role_rolls_the_whole_confirm_back(): void
    {
        AccountDefault::where('rule_key', AccountRole::AccountsPayable->value)->delete();
        app(\Modules\Finance\Services\AccountResolver::class)->flush();

        $grn = $this->confirmedGrn(10000);

        $id = $this->createPayment([
            'allocations' => [['reference_type' => 'grn', 'reference_id' => $grn->id, 'payment_amount' => 10000, 'discount' => 0]],
            'settlements' => [$this->settlement($this->bankA, 10000, '777777')],
        ]);

        $this->actingAs($this->user)->postJson("/api/v1/supplier-payments/{$id}/confirm")->assertStatus(422);

        $this->assertDatabaseCount('fin_journal_entries', 0);
        $this->assertSame('draft', SupplierPayment::find($id)->status->value);
    }

    // ── The independence proof ──────────────────────────────────────────────

    /**
     * An Inventory-only client. The payment must confirm exactly as it did
     * before Finance existed, and nothing may reach the ledger.
     */
    public function test_a_payment_confirms_normally_with_finance_disabled(): void
    {
        app(SettingsService::class)->set('module.finance', false);

        $grn = $this->confirmedGrn(10000);

        $payment = $this->confirmPayment([
            'allocations' => [['reference_type' => 'grn', 'reference_id' => $grn->id, 'payment_amount' => 10000, 'discount' => 0]],
            // No Pay From account either, because such a client has no chart of
            // accounts to choose from. It must still confirm.
            'settlements' => [[
                'payment_mode_id' => $this->cheque->id,
                'amount'          => 10000,
                'reference_no'    => '888888',
                'instrument_date' => '2026-04-12',
            ]],
        ]);

        $this->assertSame('confirmed', $payment->status->value);
        $this->assertDatabaseCount('fin_journal_entries', 0);
    }

    // -- The bridge: why all four documents have to post ------------------

    /**
     * A GRN and the payment that settles it must leave Payables at zero.
     *
     * The half-a-bridge problem made concrete. The GRN credits Payables, the
     * payment debits it, and only together do they square. Post the payment
     * alone and the account reads as a negative liability.
     */
    public function test_a_grn_and_its_payment_leave_payables_square(): void
    {
        $grn = $this->confirmedGrn(100000, withItem: true);

        // Post the GRN side the way its own confirm() now does.
        app(\App\Contracts\JournalPoster::class)
            ->post(\Modules\Inventory\Posting\GoodsReceivedNoteJournal::build($grn));

        $payables = $this->mapped(AccountRole::AccountsPayable);

        $this->assertSame(
            -100000.0,
            $this->balance($payables),
            'After the GRN alone we owe 100,000 -- a credit balance on a liability.',
        );

        $this->confirmPayment([
            'allocations' => [['reference_type' => 'grn', 'reference_id' => $grn->id, 'payment_amount' => 100000, 'discount' => 0]],
            'settlements' => [$this->settlement($this->bankA, 100000, '999999')],
        ]);

        $this->assertSame(0.0, $this->balance($payables), 'Paying in full must clear the liability exactly.');

        $trial = app(\Modules\Finance\Services\TrialBalanceService::class)->build();
        $this->assertTrue($trial['totals']['balanced']);
        $this->assertSame($trial['totals']['debit'], $trial['totals']['credit']);
    }

    // -- Void --------------------------------------------------------------

    public function test_voiding_a_payment_reverses_the_journal_and_reopens_the_grn(): void
    {
        $grn = $this->confirmedGrn(100000, withItem: true);

        app(\App\Contracts\JournalPoster::class)
            ->post(\Modules\Inventory\Posting\GoodsReceivedNoteJournal::build($grn));

        $payment = $this->confirmPayment([
            'allocations' => [['reference_type' => 'grn', 'reference_id' => $grn->id, 'payment_amount' => 100000, 'discount' => 0]],
            'settlements' => [$this->settlement($this->bankA, 100000, '101010')],
        ]);

        $payables = $this->mapped(AccountRole::AccountsPayable);
        $this->assertSame(0.0, $this->balance($payables));

        $this->actingAs($this->user)
            ->postJson("/api/v1/supplier-payments/{$payment->id}/void", ['reason' => 'Wrong amount'])
            ->assertOk()
            ->assertJsonPath('data.status', 'voided');

        // The payment journal is reversed, so the debt is owed again -- and the
        // bank is no longer out of pocket.
        $this->assertSame(-100000.0, $this->balance($payables), 'Voiding must restore the liability.');
        $this->assertSame(0.0, $this->balance($this->bankA->id), 'And put the money back.');

        // computeOutstanding() counts only CONFIRMED payments, so the GRN
        // becomes payable again with no extra bookkeeping anywhere.
        $this->assertSame(
            100000.0,
            round($this->outstandingFor($grn->id), 2),
            'The GRN is outstanding again.',
        );

        $trial = app(\Modules\Finance\Services\TrialBalanceService::class)->build();
        $this->assertTrue($trial['totals']['balanced'], 'Original and reversal both count, so it still balances.');
    }

    public function test_a_voided_payment_cannot_be_voided_again(): void
    {
        $payment = $this->simplePayment(10000, '121212');

        $this->actingAs($this->user)->postJson("/api/v1/supplier-payments/{$payment->id}/void")->assertOk();
        $this->actingAs($this->user)->postJson("/api/v1/supplier-payments/{$payment->id}/void")->assertStatus(422);
    }

    public function test_a_voided_payment_can_never_be_deleted(): void
    {
        $payment = $this->simplePayment(10000, '131313');

        $this->actingAs($this->user)->postJson("/api/v1/supplier-payments/{$payment->id}/void")->assertOk();

        // The document stays exactly as it was. Erasing it would erase the
        // record that the mistake happened.
        $this->actingAs($this->user)->deleteJson("/api/v1/supplier-payments/{$payment->id}")->assertStatus(422);
        $this->assertDatabaseHas('inv_supplier_payments', ['id' => $payment->id, 'deleted_at' => null]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * A confirmed GRN, created directly.
     *
     * Bypasses GoodsReceivedNoteService::confirm(), which demands rolls and
     * batch assignments that have nothing to do with posting. withItem adds the
     * one line the GRN journal needs: with no items it would compute zero cost
     * against a non-zero total and refuse to balance -- correct behaviour on
     * inconsistent data, but not what these tests are about.
     */
    private function confirmedGrn(float $total, bool $withItem = false): GoodsReceivedNote
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

        if ($withItem) {
            \Modules\Inventory\Models\GoodsReceivedNoteItem::create([
                'grn_id'            => $grn->id,
                'product_id'        => 1, // soft link; no product row needed to post
                'quantity_received' => 1,
                'unit_price'        => $total,
                'discount'          => 0,
                'tax'               => 0,
                'line_total'        => $total,
            ]);
        }

        return $grn->fresh('items');
    }

    /** A confirmed payment settling one GRN in full, for the void tests. */
    private function simplePayment(float $amount, string $chequeNo): SupplierPayment
    {
        $grn = $this->confirmedGrn($amount, withItem: true);

        return $this->confirmPayment([
            'allocations' => [['reference_type' => 'grn', 'reference_id' => $grn->id, 'payment_amount' => $amount, 'discount' => 0]],
            'settlements' => [$this->settlement($this->bankA, $amount, $chequeNo)],
        ]);
    }

    /** The live outstanding on a GRN, computed the way computeOutstanding() does. */
    private function outstandingFor(int $grnId): float
    {
        $paid = \Illuminate\Support\Facades\DB::table('inv_supplier_payment_allocations as a')
            ->join('inv_supplier_payments as p', 'p.id', '=', 'a.payment_id')
            ->where('a.reference_type', 'grn')
            ->where('a.reference_id', $grnId)
            ->where('p.status', 'confirmed')
            ->selectRaw('COALESCE(SUM(a.payment_amount + a.discount), 0) as total')
            ->value('total');

        $total = \Illuminate\Support\Facades\DB::table('inv_goods_received_notes')
            ->where('id', $grnId)
            ->value('total_amount');

        return (float) $total - (float) $paid;
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
            'instrument_date'   => '2026-04-12',
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function createPayment(array $overrides): int
    {
        $payload = array_merge([
            'payment_date' => '2026-04-12',
            'supplier_id'  => $this->supplier->id,
            'is_advance'   => false,
            'allocations'  => [],
            'setoffs'      => [],
            'settlements'  => [],
        ], $overrides);

        return (int) $this->actingAs($this->user)
            ->postJson('/api/v1/supplier-payments', $payload)
            ->assertCreated()
            ->json('data.id');
    }

    /** @param array<string, mixed> $overrides */
    private function confirmPayment(array $overrides): SupplierPayment
    {
        $id = $this->createPayment($overrides);

        $this->actingAs($this->user)
            ->postJson("/api/v1/supplier-payments/{$id}/confirm")
            ->assertOk();

        return SupplierPayment::findOrFail($id);
    }

    private function mapped(AccountRole $role): int
    {
        return (int) AccountDefault::where('rule_key', $role->value)->value('ledger_account_id');
    }

    /** Debits minus credits for one account, across everything in the ledger. */
    private function balance(int $accountId): float
    {
        $lines = \Modules\Finance\Models\JournalLine::inLedger()
            ->where('ledger_account_id', $accountId)
            ->get(['debit', 'credit']);

        return round($lines->sum(fn ($l): float => (float) $l->debit - (float) $l->credit), 2);
    }
}
