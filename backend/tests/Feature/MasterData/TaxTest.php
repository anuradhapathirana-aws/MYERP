<?php

declare(strict_types=1);

namespace Tests\Feature\MasterData;

use App\Models\Tax;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\TaxService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Master Data > Taxes: the one place every module reads VAT and SSCL from.
 * The seed migration has already inserted VAT 18 (active), SSCL 1.25 (off —
 * no old setting) and EXEMPT 0.
 */
class TaxTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = ['view_taxes', 'create_taxes', 'edit_taxes', 'delete_taxes'];
        foreach ($permissions as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['inventory']]);
        $this->user->givePermissionTo($permissions);
    }

    public function test_built_in_taxes_are_seeded_with_sscl_switched_off(): void
    {
        $taxes = app(TaxService::class);

        $this->assertSame(18.0, $taxes->rate(Tax::VAT));
        $this->assertSame(0.0, $taxes->rate(Tax::SSCL));          // off → nothing split out
        $this->assertSame(1.25, $taxes->configuredRate(Tax::SSCL)); // still the costing default
        $this->assertFalse($taxes->isActive(Tax::SSCL));
        $this->assertSame(0.0, $taxes->rate(Tax::EXEMPT));
    }

    public function test_the_seed_carries_over_an_sscl_rate_set_on_the_old_finance_screen(): void
    {
        DB::table('core_taxes')->delete();
        app(SettingsService::class)->set('tax.sscl_rate', 1.5);

        (require base_path('database/migrations/2026_11_02_000002_seed_core_taxes.php'))->up();

        $this->assertSame(1.5, app(TaxService::class)->rate(Tax::SSCL));
        $this->assertTrue(app(TaxService::class)->isActive(Tax::SSCL));
    }

    public function test_index_lists_rates_and_marks_the_ones_in_force(): void
    {
        $vatId = Tax::where('code', Tax::VAT)->value('id');

        $response = $this->actingAs($this->user)->getJson('/api/v1/taxes')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'code', 'name', 'tax_type', 'rate', 'effective_from', 'is_active', 'is_system']],
                'meta' => ['current_page', 'total', 'current_ids'],
            ]);

        $this->assertContains($vatId, $response->json('meta.current_ids'));
    }

    public function test_a_new_rate_period_applies_from_its_date_only(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/taxes', [
            'code' => 'vat', 'name' => 'Value Added Tax', 'tax_type' => 'vat',
            'rate' => 20, 'effective_from' => '2027-01-01', 'is_active' => true,
        ])->assertCreated()->assertJsonPath('data.code', 'VAT');

        $taxes = app(TaxService::class);
        $this->assertSame(18.0, $taxes->rate(Tax::VAT, CarbonImmutable::parse('2026-12-31')));
        $this->assertSame(20.0, $taxes->rate(Tax::VAT, CarbonImmutable::parse('2027-01-01')));
    }

    public function test_switching_sscl_on_is_read_immediately(): void
    {
        $sscl = Tax::where('code', Tax::SSCL)->first();

        $this->actingAs($this->user)->getJson('/api/v1/taxes/current')
            ->assertOk()->assertJsonPath('data.sscl', 0)->assertJsonPath('data.sscl_active', false);

        $this->actingAs($this->user)->putJson("/api/v1/taxes/{$sscl->id}", [
            'code' => 'SSCL', 'name' => $sscl->name, 'tax_type' => 'sscl',
            'rate' => 1.25, 'effective_from' => $sscl->effective_from->format('Y-m-d'), 'is_active' => true,
        ])->assertOk();

        $this->actingAs($this->user)->getJson('/api/v1/taxes/current')
            ->assertJsonPath('data.sscl', 1.25)->assertJsonPath('data.sscl_active', true);
        $this->assertDatabaseHas('core_audit_logs', ['auditable_type' => Tax::class, 'auditable_id' => $sscl->id, 'event' => 'updated']);
    }

    public function test_a_switched_off_latest_period_does_not_bring_back_an_older_rate(): void
    {
        Tax::where('code', Tax::SSCL)->update(['is_active' => true]);
        app(TaxService::class)->create(new \App\DTOs\TaxData('SSCL', 'SSCL off', \App\Enums\TaxType::Sscl, 1.25, '2026-01-01', false));

        $this->assertSame(1.25, app(TaxService::class)->rate(Tax::SSCL, CarbonImmutable::parse('2025-12-31')));
        $this->assertSame(0.0, app(TaxService::class)->rate(Tax::SSCL, CarbonImmutable::parse('2026-06-01')));
    }

    public function test_a_built_in_tax_keeps_its_code_and_cannot_be_deleted(): void
    {
        $vat = Tax::where('code', Tax::VAT)->first();

        $this->actingAs($this->user)->putJson("/api/v1/taxes/{$vat->id}", [
            'code' => 'GST', 'name' => 'Renamed', 'tax_type' => 'vat',
            'rate' => 18, 'effective_from' => $vat->effective_from->format('Y-m-d'),
        ])->assertStatus(422)->assertJsonValidationErrors('code');

        $this->actingAs($this->user)->deleteJson("/api/v1/taxes/{$vat->id}")
            ->assertStatus(422)->assertJsonValidationErrors('id');

        $this->assertDatabaseHas('core_taxes', ['id' => $vat->id, 'code' => 'VAT']);
    }

    public function test_an_added_rate_period_can_be_deleted(): void
    {
        $row = app(TaxService::class)->create(new \App\DTOs\TaxData('VAT', 'VAT', \App\Enums\TaxType::Vat, 20, '2027-01-01', true));

        $this->actingAs($this->user)->deleteJson("/api/v1/taxes/{$row->id}")->assertNoContent();
        $this->assertSame(18.0, app(TaxService::class)->rate(Tax::VAT, CarbonImmutable::parse('2027-06-01')));
    }

    public function test_validation_rejects_bad_rates_duplicate_dates_and_a_type_change(): void
    {
        // SSCL above 10 is a typo
        $this->actingAs($this->user)->postJson('/api/v1/taxes', [
            'code' => 'SSCL', 'name' => 'SSCL', 'tax_type' => 'sscl', 'rate' => 12, 'effective_from' => '2027-01-01',
        ])->assertStatus(422)->assertJsonValidationErrors('rate');

        // VAT already has a period starting 2024-01-01
        $this->actingAs($this->user)->postJson('/api/v1/taxes', [
            'code' => 'VAT', 'name' => 'VAT', 'tax_type' => 'vat', 'rate' => 15, 'effective_from' => '2024-01-01',
        ])->assertStatus(422)->assertJsonValidationErrors('effective_from');

        // VAT cannot become SSCL from next year
        $this->actingAs($this->user)->postJson('/api/v1/taxes', [
            'code' => 'VAT', 'name' => 'VAT', 'tax_type' => 'sscl', 'rate' => 2, 'effective_from' => '2027-01-01',
        ])->assertStatus(422)->assertJsonValidationErrors('tax_type');

        $this->actingAs($this->user)->postJson('/api/v1/taxes', [
            'code' => 'BAD CODE', 'name' => 'x', 'tax_type' => 'other', 'rate' => -1, 'effective_from' => 'nope',
        ])->assertStatus(422)->assertJsonValidationErrors(['code', 'rate', 'effective_from']);
    }

    public function test_writes_need_their_permissions_but_current_rates_do_not(): void
    {
        $viewer = User::factory()->create(['active_modules' => ['inventory']]);
        $viewer->givePermissionTo('view_taxes');

        $this->actingAs($viewer)->getJson('/api/v1/taxes')->assertOk();
        $this->actingAs($viewer)->postJson('/api/v1/taxes', [
            'code' => 'X', 'name' => 'X', 'tax_type' => 'other', 'rate' => 1, 'effective_from' => '2027-01-01',
        ])->assertForbidden();

        $nobody = User::factory()->create(['active_modules' => ['inventory']]);
        $this->actingAs($nobody)->getJson('/api/v1/taxes')->assertForbidden();
        $this->actingAs($nobody)->getJson('/api/v1/taxes/current')->assertOk()->assertJsonPath('data.vat', 18);
    }
}
