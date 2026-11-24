<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AmountPrecision;
use App\Support\Journal\JournalLineRequest;
use App\Support\Money\Amount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Support\Ledger\LedgerMoney;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * System Settings > Number Format: the system-wide Amount decimals setting,
 * its lock once documents exist, and the code that rounds by it.
 */
class AmountPrecisionTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/system/amount-precision';

    private User $superAdmin;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        $this->superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $this->superAdmin->assignRole('super_admin');

        $this->staff = User::factory()->create();
    }

    public function test_it_defaults_to_two_decimals_and_every_user_can_read_it(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();

        $this->actingAs($this->staff)->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.decimals', 2)
            ->assertJsonPath('data.max', 2)
            ->assertJsonPath('data.locked', false);

        $this->actingAs($this->staff)->getJson('/api/me')->assertOk()->assertJsonPath('data.amount_decimals', 2);
    }

    public function test_a_super_admin_can_change_it_before_any_document_exists_and_it_is_audited(): void
    {
        $this->actingAs($this->superAdmin)->putJson(self::URL, ['decimals' => 0])
            ->assertOk()
            ->assertJsonPath('data.decimals', 0);

        $this->assertSame(0, app(AmountPrecision::class)->decimals());

        $log = AuditLog::query()->where('event', 'amount_decimals_updated')->firstOrFail();
        $this->assertSame(['decimals' => 2], $log->old_values);
        $this->assertSame(['decimals' => 0], $log->new_values);
    }

    public function test_only_a_super_admin_can_change_it(): void
    {
        $this->actingAs($this->staff)->putJson(self::URL, ['decimals' => 0])->assertForbidden();
        $this->assertSame(2, app(AmountPrecision::class)->decimals());
    }

    public function test_it_locks_as_soon_as_a_document_exists(): void
    {
        DB::table('fin_journal_entries')->insert([
            'journal_no' => 'JV-1', 'journal_date' => '2026-04-01', 'journal_type' => 'general',
            'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->staff)->getJson(self::URL)
            ->assertJsonPath('data.locked', true)
            ->assertJsonPath('data.lock_reason', 'Documents already exist: 1 journal.');

        $this->actingAs($this->superAdmin)->putJson(self::URL, ['decimals' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decimals');

        // Saving the value it already has is not a change, so it is allowed.
        $this->actingAs($this->superAdmin)->putJson(self::URL, ['decimals' => 2])->assertOk();
        $this->assertSame(2, app(AmountPrecision::class)->decimals());
    }

    public function test_values_outside_zero_to_two_are_refused(): void
    {
        // Finance money columns are decimal(15,2): a third decimal would be cut off.
        foreach ([3, 4, -1, 'two'] as $bad) {
            $this->actingAs($this->superAdmin)->putJson(self::URL, ['decimals' => $bad])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('decimals');
        }
    }

    public function test_the_raw_settings_endpoint_cannot_bypass_the_lock(): void
    {
        $this->actingAs($this->superAdmin)
            ->putJson('/api/settings/' . AmountPrecision::SETTING_KEY, ['value' => 4])
            ->assertUnprocessable();

        $this->assertSame(2, app(AmountPrecision::class)->decimals());
    }

    public function test_amounts_journal_lines_and_ledger_balances_round_by_the_setting(): void
    {
        $this->assertSame(123.46, Amount::round(123.4567));
        $this->assertSame(0.005, Amount::tolerance());
        $this->assertSame(10.01, JournalLineRequest::debit('x', 10.005)->debit);
        $this->assertSame(1.0, LedgerMoney::money(LedgerMoney::units('0.9999')));

        $this->actingAs($this->superAdmin)->putJson(self::URL, ['decimals' => 0])->assertOk();

        $this->assertSame(123.0, Amount::round(123.4567));
        $this->assertSame(0.5, Amount::tolerance());
        $this->assertTrue(Amount::equals(10.2, 9.8));
        $this->assertSame(11.0, JournalLineRequest::credit('x', 10.5)->credit);
        $this->assertSame(-3.0, LedgerMoney::money(LedgerMoney::units('-2.5')));
    }

    public function test_the_audit_lists_over_precise_amounts_and_changes_nothing(): void
    {
        DB::table('inv_sales_orders')->insert([
            'so_no' => 'SO-1', 'customer_id' => 1, 'sales_person_id' => 1, 'order_date' => '2026-04-01', 'subtotal' => 60375.0186, 'grand_total' => 60375.0186,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('precision:audit')
            ->expectsOutputToContain('Read-only scan; nothing is changed.')
            ->expectsOutputToContain('money columns hold values with more than 2 decimals')
            ->assertSuccessful();

        $this->assertEquals(60375.0186, (float) DB::table('inv_sales_orders')->value('grand_total'));
    }
}
