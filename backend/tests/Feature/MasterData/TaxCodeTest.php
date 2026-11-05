<?php

declare(strict_types=1);

namespace Tests\Feature\MasterData;

use App\Models\Tax;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\TaxCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Master Data > Tax Codes: what a GRN line picks. The seed migration has put
 * in VAT-IN, SSCL-VAT-IN, NO-TAX-INV, IMPORT and EXEMPT-IN, plus the
 * supplier-side SSCL_PUR 2.5 % rate.
 */
class TaxCodeTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = ['view_taxes', 'create_taxes', 'edit_taxes', 'delete_taxes'];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['inventory']]);
        $this->user->givePermissionTo(self::PERMISSIONS);
    }

    public function test_the_built_in_codes_are_seeded(): void
    {
        $this->assertSame(
            ['VAT-IN', 'SSCL-VAT-IN', 'NO-TAX-INV', 'IMPORT', 'EXEMPT-IN'],
            TaxCode::orderBy('sort_order')->pluck('code')->all(),
        );
        $this->assertTrue(TaxCode::where('is_system', false)->doesntExist());
        $this->assertSame(2.5, (float) Tax::where('code', 'SSCL_PUR')->value('rate'));

        $ssclVat = TaxCode::where('code', 'SSCL-VAT-IN')->with('components')->first();
        $this->assertSame(['SSCL_PUR', 'VAT'], $ssclVat->components->pluck('tax_code_ref')->all());
        $this->assertTrue($ssclVat->components[1]->is_compound);
        $this->assertFalse($ssclVat->components[0]->is_recoverable);
    }

    public function test_options_give_each_code_with_its_rates_for_the_grn_form(): void
    {
        $options = collect($this->actingAs($this->user)->getJson('/api/v1/tax-codes/options')
            ->assertOk()->json('data'))->keyBy('code');

        $this->assertSame(18, $options['VAT-IN']['components'][0]['rate']);
        $this->assertTrue($options['VAT-IN']['components'][0]['is_recoverable']);
        $this->assertSame([], $options['IMPORT']['components']);
        $this->assertSame('import', $options['IMPORT']['vat_category']);
    }

    /** A business that is not VAT registered switches VAT off: the VAT it pays becomes cost. */
    public function test_vat_is_not_claimable_while_vat_is_switched_off(): void
    {
        Tax::where('code', 'VAT')->update(['is_active' => false]);
        Cache::forget('core_taxes.rows');

        $options = collect(app(TaxCodeService::class)->purchaseOptions())->keyBy('code');

        $this->assertSame(18.0, $options['VAT-IN']['components'][0]['rate'], 'The supplier still charges it.');
        $this->assertFalse($options['VAT-IN']['components'][0]['is_recoverable']);
    }

    public function test_an_inactive_code_drops_out_of_the_options(): void
    {
        TaxCode::where('code', 'EXEMPT-IN')->update(['is_active' => false]);

        $codes = collect(app(TaxCodeService::class)->purchaseOptions())->pluck('code');

        $this->assertNotContains('EXEMPT-IN', $codes);
    }

    public function test_a_custom_code_can_be_created_edited_and_deleted_with_an_audit_trail(): void
    {
        $id = $this->actingAs($this->user)->postJson('/api/v1/tax-codes', [
            'code' => 'vat-half', 'name' => 'VAT, half claimable', 'vat_category' => 'standard',
            'components' => [['tax_code_ref' => 'VAT', 'is_compound' => false, 'is_recoverable' => false]],
        ])->assertCreated()
            ->assertJsonPath('data.code', 'VAT-HALF')
            ->assertJsonPath('data.components.0.tax_code_ref', 'VAT')
            ->json('data.id');

        $this->actingAs($this->user)->putJson("/api/v1/tax-codes/{$id}", [
            'code' => 'VAT-HALF', 'name' => 'Renamed', 'vat_category' => 'standard', 'is_active' => false,
            'components' => [['tax_code_ref' => 'VAT', 'is_compound' => false, 'is_recoverable' => true]],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.components.0.is_recoverable', true);

        $this->actingAs($this->user)->deleteJson("/api/v1/tax-codes/{$id}")->assertNoContent();

        $this->assertDatabaseMissing('core_tax_codes', ['id' => $id]);
        foreach (['created', 'updated', 'deleted'] as $event) {
            $this->assertDatabaseHas('core_audit_logs', ['auditable_type' => TaxCode::class, 'auditable_id' => $id, 'event' => $event]);
        }
    }

    public function test_a_built_in_code_keeps_its_taxes_but_can_be_renamed_or_switched_off(): void
    {
        $vatIn = TaxCode::where('code', 'VAT-IN')->first();

        $this->actingAs($this->user)->putJson("/api/v1/tax-codes/{$vatIn->id}", [
            'code' => 'VAT-IN', 'name' => 'VAT', 'vat_category' => 'standard',
            'components' => [['tax_code_ref' => 'VAT', 'is_compound' => false, 'is_recoverable' => false]],
        ])->assertStatus(422)->assertJsonValidationErrors('code');

        $this->actingAs($this->user)->putJson("/api/v1/tax-codes/{$vatIn->id}", [
            'code' => 'VAT-IN', 'name' => 'VAT with Tax Invoice', 'vat_category' => 'standard', 'is_active' => false,
            'components' => [['tax_code_ref' => 'VAT', 'is_compound' => false, 'is_recoverable' => true]],
        ])->assertOk()->assertJsonPath('data.name', 'VAT with Tax Invoice')->assertJsonPath('data.is_active', false);

        $this->actingAs($this->user)->deleteJson("/api/v1/tax-codes/{$vatIn->id}")
            ->assertStatus(422)->assertJsonValidationErrors('id');
    }

    public function test_a_code_a_supplier_starts_with_cannot_be_deleted(): void
    {
        $code = app(TaxCodeService::class)->create(new \App\DTOs\TaxCodeData('LOCAL-X', 'Local X', \App\Enums\VatCategory::OutOfScope, true, 0, []));
        DB::table('inv_supplier_masters')->insert([
            'supplier_code' => 'SUP-1', 'supplier_name' => 'ABC', 'default_tax_code_id' => $code->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->user)->deleteJson("/api/v1/tax-codes/{$code->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('id')
            ->assertJsonPath('errors.id.0', 'Tax code LOCAL-X is used as the default of 1 supplier. Switch it off (Inactive) instead.');
    }

    public function test_a_tax_a_code_is_built_on_cannot_lose_its_last_rate_period(): void
    {
        $tax = app(\App\Services\TaxService::class)->create(new \App\DTOs\TaxData('CESS', 'Cess', \App\Enums\TaxType::Other, 5, '2026-01-01', true));
        app(TaxCodeService::class)->create(new \App\DTOs\TaxCodeData('CESS-IN', 'Cess', \App\Enums\VatCategory::Standard, true, 0, [
            ['tax_code_ref' => 'CESS', 'is_compound' => false, 'is_recoverable' => false],
        ]));

        $this->actingAs($this->user)->deleteJson("/api/v1/taxes/{$tax->id}")
            ->assertStatus(422)->assertJsonValidationErrors('id');
    }

    public function test_validation_keeps_categories_and_taxes_consistent(): void
    {
        // Standard rated with no tax
        $this->actingAs($this->user)->postJson('/api/v1/tax-codes', [
            'code' => 'A', 'name' => 'A', 'vat_category' => 'standard', 'components' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('components');

        // Exempt carrying a tax
        $this->actingAs($this->user)->postJson('/api/v1/tax-codes', [
            'code' => 'B', 'name' => 'B', 'vat_category' => 'exempt',
            'components' => [['tax_code_ref' => 'VAT']],
        ])->assertStatus(422)->assertJsonValidationErrors('components');

        // Unknown tax, the same tax twice, duplicate code, bad code
        $this->actingAs($this->user)->postJson('/api/v1/tax-codes', [
            'code' => 'VAT-IN', 'name' => 'C', 'vat_category' => 'standard',
            'components' => [['tax_code_ref' => 'NOPE'], ['tax_code_ref' => 'VAT'], ['tax_code_ref' => 'VAT']],
        ])->assertStatus(422)->assertJsonValidationErrors(['code', 'components.0.tax_code_ref', 'components.1.tax_code_ref']);

        $this->actingAs($this->user)->postJson('/api/v1/tax-codes', [
            'code' => 'BAD CODE', 'name' => '', 'vat_category' => 'nope', 'components' => [],
        ])->assertStatus(422)->assertJsonValidationErrors(['code', 'name', 'vat_category']);
    }

    public function test_writes_need_permissions_but_the_options_do_not(): void
    {
        $nobody = User::factory()->create(['active_modules' => ['inventory']]);

        $this->actingAs($nobody)->getJson('/api/v1/tax-codes')->assertForbidden();
        $this->actingAs($nobody)->postJson('/api/v1/tax-codes', [])->assertForbidden();
        $this->actingAs($nobody)->getJson('/api/v1/tax-codes/options')->assertOk();
    }
}
