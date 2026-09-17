<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\Company;
use Modules\Inventory\Models\Industry;
use Modules\Inventory\Models\Location;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * open_hours_* are TIME columns, so the database hands them back as "H:i:s".
 * Re-saving an existing location must not fail validation because of that.
 */
class LocationUpdateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = ['view_locations', 'edit_locations'];
        foreach ($permissions as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['inventory']]);
        $this->user->givePermissionTo($permissions);

        $industry = Industry::create(['name' => 'Retail']);
        $company  = Company::create(['company_name' => 'Test Co', 'industry_id' => $industry->id]);

        $this->location = Location::create([
            'company_id'             => $company->id,
            'industry_id'            => $industry->id,
            'location_code'          => 'LOC-001',
            'location_name'          => 'Head Office',
            'country'                => 'Sri Lanka',
            'loc_street_address'     => '1 Main St',
            'loc_city'               => 'Colombo',
            'loc_country'            => 'Sri Lanka',
            'loc_state'              => 'Western',
            'loc_postal_zip_code'    => '00100',
            'billing_same_as_location' => true,
            'base_currency'          => 'LKR',
            'financial_year'         => 'Apr-Mar',
            'open_hours_from'        => '09:00:00',
            'open_hours_to'          => '18:00:00',
            'stock_releasing_method' => 'FIFO',
        ]);
    }

    public function test_show_returns_open_hours_as_hours_and_minutes(): void
    {
        $this->actingAs($this->user)
            ->getJson("/api/v1/locations/{$this->location->id}")
            ->assertOk()
            ->assertJsonPath('data.open_hours_from', '09:00')
            ->assertJsonPath('data.open_hours_to', '18:00');
    }

    public function test_update_accepts_open_hours_with_seconds(): void
    {
        $payload = $this->payload(['location_name' => 'Head Office Updated']);

        $this->actingAs($this->user)
            ->putJson("/api/v1/locations/{$this->location->id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.location_name', 'Head Office Updated')
            ->assertJsonPath('data.open_hours_from', '09:00');
    }

    public function test_update_rejects_closing_time_before_opening_time(): void
    {
        $payload = $this->payload(['open_hours_from' => '18:00', 'open_hours_to' => '09:00']);

        $this->actingAs($this->user)
            ->putJson("/api/v1/locations/{$this->location->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['open_hours_to']);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'company_id'               => $this->location->company_id,
            'industry_id'              => $this->location->industry_id,
            'location_code'            => 'LOC-001',
            'location_name'            => 'Head Office',
            'country'                  => 'Sri Lanka',
            'loc_street_address'       => '1 Main St',
            'loc_city'                 => 'Colombo',
            'loc_country'              => 'Sri Lanka',
            'loc_state'                => 'Western',
            'loc_postal_zip_code'      => '00100',
            'billing_same_as_location' => true,
            'base_currency'            => 'LKR',
            'financial_year'           => 'Apr-Mar',
            'open_hours_from'          => '09:00:00',
            'open_hours_to'            => '18:00:00',
            'available_modules'        => ['Inventory'],
            'stock_releasing_method'   => 'FIFO',
        ], $overrides);
    }
}
