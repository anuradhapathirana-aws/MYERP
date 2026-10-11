<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Database\Seeders\FinanceChartOfAccountsSeeder;
use Modules\Finance\Database\Seeders\FinanceGlAccountsSeeder;
use Modules\Finance\Enums\AccountRole;
use Modules\Finance\Enums\JournalStatus;
use Modules\Finance\Models\AccountDefault;
use Modules\Finance\Models\JournalEntry;
use Modules\Finance\Models\LedgerAccount;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The Journal Voucher screen, Trial Balance and Finance Settings, over HTTP.
 *
 * JournalPostingTest proves the engine. This proves the surface a user
 * actually touches: that the form cannot post something unbalanced, that a
 * posted voucher cannot be deleted, that the Trial Balance balances, and that
 * the account-mapping type guard refuses a nonsensical mapping.
 */
class JournalVoucherApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        app(SettingsService::class)->set('module.finance', true);

        $permissions = [
            'view_journal_entries', 'create_journal_entries', 'post_journal_entries',
            'reverse_journal_entries', 'delete_journal_entries',
            'view_finance_reports', 'view_finance_settings', 'manage_finance_settings',
        ];

        foreach ($permissions as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['finance']]);
        $this->user->givePermissionTo($permissions);

        $this->seed(FinanceChartOfAccountsSeeder::class);
        $this->seed(FinanceGlAccountsSeeder::class);
    }

    // ── Posting a voucher ────────────────────────────────────────────────────

    public function test_a_balanced_voucher_posts(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/journal-entries', $this->voucher(8000, 8000));

        $response->assertCreated()
            ->assertJsonPath('data.journal_no', 'JV-2026-0001')
            ->assertJsonPath('data.status', JournalStatus::Posted->value)
            ->assertJsonPath('data.is_balanced', true)
            ->assertJsonCount(2, 'data.lines');

        $this->assertMoney(8000.0, $response->json('data.total_debit'));
        $this->assertMoney(8000.0, $response->json('data.total_credit'));

        $this->assertDatabaseCount('fin_journal_entries', 1);
        $this->assertDatabaseCount('fin_journal_lines', 2);
    }

    public function test_an_unbalanced_voucher_is_refused_with_the_difference(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/journal-entries', $this->voucher(8000, 7500))
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines');

        $this->assertDatabaseCount('fin_journal_entries', 0);
    }

    public function test_a_voucher_needs_two_lines_with_an_amount(): void
    {
        $payload = $this->voucher(8000, 8000);
        // Blank the second line, as the grid would submit it.
        $payload['lines'][1]['debit']  = 0;
        $payload['lines'][1]['credit'] = 0;

        $this->actingAs($this->user)
            ->postJson('/api/v1/journal-entries', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines');
    }

    public function test_a_line_cannot_hold_a_debit_and_a_credit(): void
    {
        $payload = $this->voucher(8000, 8000);
        $payload['lines'][0]['credit'] = 500;

        $this->actingAs($this->user)
            ->postJson('/api/v1/journal-entries', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines.0.debit');
    }

    public function test_an_inactive_account_cannot_be_posted_to(): void
    {
        $account = LedgerAccount::firstWhere('ledger_account_name', 'Purchases');
        $account->update(['is_active' => false]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/journal-entries', $this->voucher(8000, 8000))
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines.0.ledger_account_id');
    }

    public function test_an_automatic_journal_type_cannot_be_hand_entered(): void
    {
        $payload                 = $this->voucher(8000, 8000);
        $payload['journal_type'] = 'auto';

        // Otherwise a user could hand-write an entry claiming to have come
        // from a document.
        $this->actingAs($this->user)
            ->postJson('/api/v1/journal-entries', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('journal_type');
    }

    // ── Reading ──────────────────────────────────────────────────────────────

    public function test_the_list_returns_the_expected_shape(): void
    {
        $this->postVoucher();

        $this->actingAs($this->user)->getJson('/api/v1/journal-entries')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'journal_no', 'journal_date', 'status', 'total_debit', 'lines_count']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('meta.total', 1);
    }

    /**
     * A two-line voucher is shown as From -> To, the way Tally renders a
     * Contra, because that is how people read a transfer.
     */
    public function test_a_two_line_voucher_exposes_a_from_to_view(): void
    {
        $id = $this->postVoucher();

        $response = $this->actingAs($this->user)->getJson("/api/v1/journal-entries/{$id}")->assertOk();

        $this->assertMoney(8000.0, $response->json('data.transfer.amount'));

        $this->assertSame(
            'Accounts Payable',
            $response->json('data.transfer.from_account_name'),
            'The credit side is where the money came FROM.',
        );
        $this->assertSame(
            'Purchases',
            $response->json('data.transfer.to_account_name'),
            'The debit side is where it went TO.',
        );
    }

    public function test_the_next_number_preview_does_not_consume_it(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/journal-entries/next-no?journal_date=2026-04-30')
            ->assertOk()
            ->assertJsonPath('data.journal_no', 'JV-2026-0001');

        // Asked twice, same answer: the preview is lock-free and never persisted.
        $this->actingAs($this->user)->getJson('/api/v1/journal-entries/next-no?journal_date=2026-04-30')
            ->assertOk()
            ->assertJsonPath('data.journal_no', 'JV-2026-0001');

        $this->assertDatabaseCount('fin_journal_entries', 0);
    }

    public function test_options_serves_only_user_selectable_types(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/journal-entries/options')->assertOk();

        $types = array_column($response->json('data.journal_types'), 'value');

        $this->assertSame(['general', 'opening', 'adjustment'], $types);
        $this->assertNotContains('auto', $types);
        $this->assertNotContains('reversal', $types);
    }

    // ── Immutability ─────────────────────────────────────────────────────────

    public function test_a_posted_voucher_cannot_be_deleted(): void
    {
        $id = $this->postVoucher();

        $this->actingAs($this->user)->deleteJson("/api/v1/journal-entries/{$id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('fin_journal_entries', ['id' => $id, 'deleted_at' => null]);
    }

    public function test_there_is_no_update_route_for_a_journal(): void
    {
        $id = $this->postVoucher();

        $this->actingAs($this->user)
            ->putJson("/api/v1/journal-entries/{$id}", ['narration' => 'tampered'])
            ->assertStatus(405);
    }

    public function test_reversing_posts_a_mirror_and_retires_the_original(): void
    {
        $id = $this->postVoucher();

        $this->actingAs($this->user)
            ->postJson("/api/v1/journal-entries/{$id}/reverse", ['narration' => 'Wrong amount'])
            ->assertCreated()
            ->assertJsonPath('data.journal_no', 'JV-2026-0002')
            ->assertJsonPath('data.journal_type', 'reversal')
            ->assertJsonPath('data.narration', 'Wrong amount');

        $this->assertSame(JournalStatus::Reversed, JournalEntry::find($id)->status);
    }

    // ── Trial Balance ────────────────────────────────────────────────────────

    public function test_the_trial_balance_balances(): void
    {
        $this->postVoucher();

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/finance-reports/trial-balance')
            ->assertOk();

        $response->assertJsonPath('data.totals.balanced', true);

        $this->assertMoney(8000.0, $response->json('data.totals.debit'));
        $this->assertMoney(8000.0, $response->json('data.totals.credit'));
        $this->assertMoney(0.0, $response->json('data.totals.difference'));

        $rows = collect($response->json('data.rows'));

        $this->assertCount(2, $rows, 'Only accounts with movement appear.');
        $this->assertMoney(8000.0, $rows->firstWhere('ledger_account_name', 'Purchases')['debit_balance']);
        $this->assertMoney(8000.0, $rows->firstWhere('ledger_account_name', 'Accounts Payable')['credit_balance']);
    }

    public function test_a_reversed_pair_leaves_the_trial_balance_at_zero(): void
    {
        $id = $this->postVoucher();
        $this->actingAs($this->user)->postJson("/api/v1/journal-entries/{$id}/reverse")->assertCreated();

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/finance-reports/trial-balance')
            ->assertOk();

        // Both journals still count, so every balance nets to nothing. If the
        // reversed original were excluded, these would be 8000 the wrong way up.
        $response->assertJsonPath('data.totals.balanced', true);

        $this->assertMoney(0.0, $response->json('data.totals.debit'));
        $this->assertMoney(0.0, $response->json('data.totals.credit'));
    }

    public function test_the_trial_balance_respects_a_date_filter(): void
    {
        $this->postVoucher();

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/finance-reports/trial-balance?date_from=2027-01-01')
            ->assertOk()
            ->assertJsonCount(0, 'data.rows');

        $this->assertMoney(0.0, $response->json('data.totals.debit'));
    }

    // ── Finance Settings and the type guard ──────────────────────────────────

    public function test_settings_lists_every_role_with_its_account(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/finance-settings')->assertOk();

        $this->assertCount(16, $response->json('data.mappings'));
        $this->assertSame([], $response->json('data.missing_required'));
        $this->assertSame('review', $response->json('data.posting_mode'), 'Review is the safe default.');

        $payables = collect($response->json('data.mappings'))->firstWhere('rule_key', 'accounts_payable');

        $this->assertSame('Accounts Payable', $payables['ledger_account_name']);
        $this->assertTrue($payables['is_mapped']);
        $this->assertSame([['value' => 'liability', 'label' => 'Liabilities']], $payables['accepted_types']);
    }

    /** The guard that turns the most expensive mistake on this screen into a 422. */
    public function test_a_wrong_account_type_is_refused(): void
    {
        $salesRevenue = LedgerAccount::firstWhere('ledger_account_name', 'Sales Revenue');

        $this->actingAs($this->user)
            ->putJson('/api/v1/finance-settings/mappings', [
                'mappings' => ['accounts_payable' => $salesRevenue->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mappings.accounts_payable');

        // Unchanged: a refused save must not half-apply.
        $this->assertSame(
            'Accounts Payable',
            AccountDefault::with('ledgerAccount')
                ->firstWhere('rule_key', AccountRole::AccountsPayable->value)
                ->ledgerAccount->ledger_account_name,
        );
    }

    public function test_a_correct_account_type_is_accepted(): void
    {
        $customerAdvances = LedgerAccount::firstWhere('ledger_account_name', 'Customer Advances');

        $this->actingAs($this->user)
            ->putJson('/api/v1/finance-settings/mappings', [
                'mappings' => ['accounts_payable' => $customerAdvances->id],
            ])
            ->assertOk();

        $this->assertSame(
            $customerAdvances->id,
            AccountDefault::firstWhere('rule_key', AccountRole::AccountsPayable->value)->ledger_account_id,
        );
    }

    public function test_a_required_role_cannot_be_unmapped(): void
    {
        $this->actingAs($this->user)
            ->putJson('/api/v1/finance-settings/mappings', ['mappings' => ['accounts_payable' => null]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mappings.accounts_payable');
    }

    public function test_the_posting_mode_can_be_switched(): void
    {
        $this->actingAs($this->user)
            ->putJson('/api/v1/finance-settings/posting-mode', ['posting_mode' => 'immediate'])
            ->assertOk()
            ->assertJsonPath('data.posting_mode', 'immediate');

        $this->actingAs($this->user)->getJson('/api/v1/finance-settings')
            ->assertJsonPath('data.posting_mode', 'immediate');
    }

    // ── Access control ───────────────────────────────────────────────────────

    public function test_mapping_needs_its_own_permission(): void
    {
        $viewer = User::factory()->create(['active_modules' => ['finance']]);
        $viewer->givePermissionTo(['view_finance_settings']);

        // Can look at the mapping...
        $this->actingAs($viewer)->getJson('/api/v1/finance-settings')->assertOk();

        // ...but not change it. This is the split that lets a senior accountant
        // hold mapping rights without being an administrator, and vice versa.
        $this->actingAs($viewer)
            ->putJson('/api/v1/finance-settings/mappings', ['mappings' => ['bank_charges' => null]])
            ->assertForbidden();
    }

    public function test_posting_and_reversing_are_separate_permissions(): void
    {
        $clerk = User::factory()->create(['active_modules' => ['finance']]);
        $clerk->givePermissionTo(['view_journal_entries', 'create_journal_entries']);

        $id = $this->postVoucher();

        $this->actingAs($clerk)->getJson("/api/v1/journal-entries/{$id}")->assertOk();
        $this->actingAs($clerk)->postJson("/api/v1/journal-entries/{$id}/reverse")->assertForbidden();
    }

    public function test_the_whole_ledger_is_unreachable_without_the_module(): void
    {
        app(SettingsService::class)->set('module.finance', false);

        foreach ([
            '/api/v1/journal-entries',
            '/api/v1/journal-entries/options',
            '/api/v1/finance-settings',
            '/api/v1/finance-reports/trial-balance',
        ] as $route) {
            $this->actingAs($this->user)->getJson($route)->assertForbidden($route);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Compare a money figure from a JSON payload.
     *
     * json_encode renders 8000.0 as 8000, so assertJsonPath's strict compare
     * fails on every whole amount. Casting makes the intent explicit: what
     * matters is the value, not whether it survived as a PHP float.
     */
    private function assertMoney(float $expected, mixed $actual, string $message = ''): void
    {
        $this->assertSame($expected, (float) $actual, $message);
    }

    /** @return array<string, mixed> */
    private function voucher(float $debit, float $credit): array
    {
        return [
            'journal_date' => '2026-04-30',
            'journal_type' => 'general',
            'narration'    => 'Monthly depreciation',
            'lines'        => [
                [
                    'ledger_account_id' => LedgerAccount::firstWhere('ledger_account_name', 'Purchases')->id,
                    'debit'             => $debit,
                    'credit'            => 0,
                    'description'       => 'October charge',
                ],
                [
                    'ledger_account_id' => LedgerAccount::firstWhere('ledger_account_name', 'Accounts Payable')->id,
                    'debit'             => 0,
                    'credit'            => $credit,
                ],
            ],
        ];
    }

    private function postVoucher(): int
    {
        return (int) $this->actingAs($this->user)
            ->postJson('/api/v1/journal-entries', $this->voucher(8000, 8000))
            ->assertCreated()
            ->json('data.id');
    }
}
