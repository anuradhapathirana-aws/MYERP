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
use Modules\Finance\Enums\PartyLedgerMode;
use Modules\Finance\Models\AccountDefault;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Services\JournalPostingService;
use Modules\Finance\Services\LedgerAccountService;
use Modules\Finance\Services\PartyLedgerProvisioner;
use Modules\Inventory\Models\GoodsReceivedNote;
use Modules\Inventory\Models\GoodsReceivedNoteItem;
use Modules\Inventory\Posting\GoodsReceivedNoteJournal;
use Tests\TestCase;

/**
 * One ledger account per supplier, instead of one shared Accounts Payable.
 *
 * Both patterns are legitimate: SAP, Oracle and Odoo share one control account
 * and tell parties apart by the party on the line; Tally and Busy give each
 * party its own ledger. This makes it a setting.
 *
 * ── What these tests are really protecting ──────────────────────────────────
 * That the two modes stay INTEROPERABLE. party_id is written on the line in
 * both modes, so any report that groups by party reads identically across a
 * switch. Drop that and the choice becomes permanent and every ageing report
 * needs rewriting -- which is exactly the kind of thing that looks fine for a
 * month and then cannot be undone.
 */
class PartyLedgerModeTest extends TestCase
{
    use RefreshDatabase;

    private User           $user;
    private SupplierMaster $abc;
    private SupplierMaster $xyz;

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

        $this->user = User::factory()->create(['active_modules' => ['inventory', 'finance']]);

        $group = SupplierGroup::firstOrCreate(
            ['name' => 'Trade (Local)'],
            ['code' => 'TRADE', 'is_active' => true],
        );

        $this->abc = SupplierMaster::create([
            'supplier_code' => 'SUP-0001', 'supplier_name' => 'ABC Textiles',
            'supplier_group_id' => $group->id,
        ]);

        $this->xyz = SupplierMaster::create([
            'supplier_code' => 'SUP-0002', 'supplier_name' => 'XYZ Trading',
            'supplier_group_id' => $group->id,
        ]);

        $this->seed(FinanceChartOfAccountsSeeder::class);
        $this->seed(FinanceGlAccountsSeeder::class);
    }

    // ── Shared mode: unchanged behaviour ────────────────────────────────────

    /** The default. Nothing about the earlier design may change. */
    public function test_shared_mode_posts_every_supplier_to_one_account(): void
    {
        $this->postGrn($this->abc, 100000);
        $this->postGrn($this->xyz, 60000);

        $shared = $this->mapped(AccountRole::AccountsPayable);

        $this->assertSame(-160000.0, $this->balance($shared), 'Both suppliers land in one account.');
        $this->assertSame(0, LedgerAccount::whereNotNull('party_type')->count(), 'No party accounts created.');

        // And they are still told apart, which is the whole point of the
        // shared pattern.
        $this->assertSame(-100000.0, $this->partyBalance('supplier', $this->abc->id));
        $this->assertSame(-60000.0, $this->partyBalance('supplier', $this->xyz->id));
    }

    // ── Per-party mode ──────────────────────────────────────────────────────

    public function test_per_party_mode_creates_an_account_per_supplier_on_first_posting(): void
    {
        $this->perParty();

        $this->postGrn($this->abc, 100000);

        $abcAccount = LedgerAccount::forParty('supplier', $this->abc->id)->first();

        $this->assertNotNull($abcAccount, 'The supplier must get its own account.');
        $this->assertSame('ABC Textiles', $abcAccount->ledger_account_name, 'Named from the document.');
        $this->assertSame(-100000.0, $this->balance($abcAccount->id));

        // Nothing landed in the shared account.
        $this->assertSame(0.0, $this->balance($this->mapped(AccountRole::AccountsPayable)));

        // XYZ has not traded, so XYZ has no account. The chart grows with the
        // business rather than with the master list.
        $this->assertNull(LedgerAccount::forParty('supplier', $this->xyz->id)->first());
    }

    /**
     * The new account sits under the SAME control account as the shared one.
     *
     * This is Tally's shape -- parties as ledgers under the Sundry Creditors
     * group -- and it means moving the shared mapping also moves where new
     * party accounts are created, with no code change.
     */
    public function test_a_party_account_is_created_under_the_shared_accounts_control(): void
    {
        $this->perParty();
        $this->postGrn($this->abc, 50000);

        $shared = LedgerAccount::findOrFail($this->mapped(AccountRole::AccountsPayable));
        $party  = LedgerAccount::forParty('supplier', $this->abc->id)->firstOrFail();

        $this->assertSame($shared->control_account_id, $party->control_account_id);

        // The code is the control code plus the next sequence, so the chart
        // still sorts into a correct hierarchy.
        $this->assertStringStartsWith(
            (string) $shared->controlAccount->code,
            (string) $party->code,
        );
    }

    public function test_a_second_posting_reuses_the_same_account(): void
    {
        $this->perParty();

        $this->postGrn($this->abc, 100000);
        $this->postGrn($this->abc, 40000);

        $this->assertSame(
            1,
            LedgerAccount::forParty('supplier', $this->abc->id)->count(),
            'One account per party, never two.',
        );

        $account = LedgerAccount::forParty('supplier', $this->abc->id)->firstOrFail();
        $this->assertSame(-140000.0, $this->balance($account->id));
    }

    /** Party accounts must stay out of pickers, or the chart becomes unusable. */
    public function test_party_accounts_are_hidden_from_account_dropdowns(): void
    {
        $this->perParty();
        $this->postGrn($this->abc, 50000);

        $service = app(LedgerAccountService::class);

        $ids = $service->all()->pluck('id')->all();
        $partyId = (int) LedgerAccount::forParty('supplier', $this->abc->id)->value('id');

        $this->assertNotContains($partyId, $ids, 'A dropdown must not offer a party account.');

        // Still reachable when something genuinely needs them.
        $withParties = $service->all(['include_party_accounts' => 1])->pluck('id')->all();
        $this->assertContains($partyId, $withParties);
    }

    // ── The interoperability guarantee ──────────────────────────────────────

    /**
     * party_id is written in BOTH modes, so ageing survives a switch.
     *
     * The supplier's balance is split across two accounts after the change --
     * expected, and honest, because posted entries are never rewritten. But
     * any report grouping by party still returns one correct figure.
     */
    public function test_switching_mode_leaves_the_party_balance_queryable_as_one_figure(): void
    {
        // First GRN in shared mode.
        $this->postGrn($this->abc, 100000);
        $shared = $this->mapped(AccountRole::AccountsPayable);

        // Switch, then a second GRN for the same supplier.
        $this->perParty();
        $this->postGrn($this->abc, 40000);

        $party = (int) LedgerAccount::forParty('supplier', $this->abc->id)->value('id');

        // Split across two accounts, exactly as documented.
        $this->assertSame(-100000.0, $this->balance($shared), 'History stays where it was posted.');
        $this->assertSame(-40000.0, $this->balance($party), 'New postings go to the party account.');

        // But the subledger still answers in one number. THIS is the property
        // that keeps the switch reversible.
        $this->assertSame(
            -140000.0,
            $this->partyBalance('supplier', $this->abc->id),
            'Aged Payables reads the party, not the account, so it is unaffected.',
        );

        $this->assertTrue(
            app(\Modules\Finance\Services\TrialBalanceService::class)->build()['totals']['balanced'],
        );
    }

    /** Switching back must not strand anything. */
    public function test_switching_back_to_shared_resumes_the_shared_account(): void
    {
        $this->perParty();
        $this->postGrn($this->abc, 40000);

        app(SettingsService::class)->set(
            PartyLedgerProvisioner::SETTING_SUPPLIER_MODE,
            PartyLedgerMode::Shared->value,
        );
        app(\Modules\Finance\Services\AccountResolver::class)->flush();

        $this->postGrn($this->abc, 10000);

        $this->assertSame(-10000.0, $this->balance($this->mapped(AccountRole::AccountsPayable)));
        $this->assertSame(-50000.0, $this->partyBalance('supplier', $this->abc->id));
    }

    /** The two sides are independent -- the common real-world case. */
    public function test_supplier_and_customer_modes_are_set_separately(): void
    {
        $this->perParty();

        $parties = app(PartyLedgerProvisioner::class);

        $this->assertSame(PartyLedgerMode::PerParty, $parties->modeFor('supplier'));
        $this->assertSame(PartyLedgerMode::Shared, $parties->modeFor('customer'));

        // Anything that is not a supplier or customer stays shared: an account
        // per employee would be noise, not information.
        $this->assertSame(PartyLedgerMode::Shared, $parties->modeFor('employee'));
    }

    /** A closed party account must refuse, not silently use the shared one. */
    public function test_an_inactive_party_account_refuses_rather_than_falling_back(): void
    {
        $this->perParty();
        $this->postGrn($this->abc, 50000);

        LedgerAccount::forParty('supplier', $this->abc->id)->update(['is_active' => false]);
        app(\Modules\Finance\Services\AccountResolver::class)->flush();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->postGrn($this->abc, 10000);
    }

    /**
     * The flag must survive the controller whitelist, not just the service.
     *
     * This is the gap that broke the Journal Voucher form: the service
     * supported include_party_accounts, the controller silently dropped it, so
     * no amount of asking from the frontend could ever work. The same
     * hand-maintained list had already hidden account_category_id the same way.
     */
    public function test_the_api_can_be_asked_for_party_accounts(): void
    {
        $this->perParty();
        $this->postGrn($this->abc, 50000);

        \Spatie\Permission\Models\Permission::findOrCreate('view_ledger_accounts', 'web');
        $this->user->givePermissionTo('view_ledger_accounts');

        $partyId = (int) LedgerAccount::forParty('supplier', $this->abc->id)->value('id');

        // Default: kept out, so ordinary pickers stay clean.
        $ids = collect($this->actingAs($this->user)
            ->getJson('/api/v1/ledger-accounts/all')
            ->assertOk()
            ->json('data'))->pluck('id')->all();

        $this->assertNotContains($partyId, $ids);

        // Asked for: present, and carrying party_type so a picker can group it.
        $withParties = collect($this->actingAs($this->user)
            ->getJson('/api/v1/ledger-accounts/all?include_party_accounts=1')
            ->assertOk()
            ->json('data'))->keyBy('id');

        $this->assertTrue($withParties->has($partyId), 'The Journal Voucher form depends on this.');
        $this->assertSame('supplier', $withParties[$partyId]['party_type']);
        $this->assertSame($this->abc->id, $withParties[$partyId]['party_id']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function perParty(): void
    {
        app(SettingsService::class)->set(
            PartyLedgerProvisioner::SETTING_SUPPLIER_MODE,
            PartyLedgerMode::PerParty->value,
        );

        // The resolver memoises per request, and a test is one request.
        app(\Modules\Finance\Services\AccountResolver::class)->flush();
    }

    private function postGrn(SupplierMaster $supplier, float $total): GoodsReceivedNote
    {
        static $seq = 0;
        $seq++;

        $grn = GoodsReceivedNote::create([
            'grn_no'       => sprintf('GRN-TST-%04d', $seq),
            'supplier_id'  => $supplier->id,
            'grn_date'     => '2026-04-05',
            'status'       => 'confirmed',
            'total_amount' => $total,
        ]);

        GoodsReceivedNoteItem::create([
            'grn_id'            => $grn->id,
            'product_id'        => 1, // soft link; no product row needed to post
            'quantity_received' => 1,
            'unit_price'        => $total,
            'discount'          => 0,
            'tax'               => 0,
            'line_total'        => $total,
        ]);

        app(JournalPoster::class)->post(GoodsReceivedNoteJournal::build($grn->fresh(['items', 'supplier'])));

        return $grn;
    }

    private function mapped(AccountRole $role): int
    {
        return (int) AccountDefault::where('rule_key', $role->value)->value('ledger_account_id');
    }

    private function balance(int $accountId): float
    {
        return round((float) JournalLine::inLedger()
            ->where('ledger_account_id', $accountId)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as bal')
            ->value('bal'), 2);
    }

    /** What Aged Payables asks: the balance for one party, across any account. */
    private function partyBalance(string $partyType, int $partyId): float
    {
        return round((float) JournalLine::inLedger()
            ->forParty($partyType, $partyId)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as bal')
            ->value('bal'), 2);
    }
}
