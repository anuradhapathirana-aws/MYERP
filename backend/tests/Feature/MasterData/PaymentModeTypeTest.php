<?php

declare(strict_types=1);

namespace Tests\Feature\MasterData;

use App\Enums\PaymentModeType;
use App\Models\PaymentMode;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The fixed Type on the shared Payment Modes master: how existing rows get
 * one, that every new mode must have one, and that built-in modes keep theirs.
 */
class PaymentModeTypeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);
        app(SettingsService::class)->set('module.finance', true);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = ['view_payment_modes', 'create_payment_modes', 'edit_payment_modes'];
        foreach ($permissions as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['inventory', 'finance']]);
        $this->user->givePermissionTo($permissions);
    }

    /** @param array<string, mixed> $overrides */
    private function body(array $overrides = []): array
    {
        return array_merge([
            'payment_mode_name' => 'CEFT', 'code' => 'ceft', 'type' => 'bank_transfer',
            'requires_reference_no' => true, 'is_active' => true,
        ], $overrides);
    }

    public function test_the_migration_types_existing_rows_from_their_code(): void
    {
        $migration = require base_path('database/migrations/2026_10_28_000001_add_type_to_inv_payment_modes.php');
        $migration->down();
        DB::table('inv_payment_modes')->delete(); // Inventory's migrations seed online_transfer

        $rows = [
            'cash' => 'cash', 'petty_cash' => 'cash', 'cheque' => 'cheque', 'card' => 'card',
            'setoff' => 'setoff', 'online_transfer' => 'bank_transfer', 'bank_deposit' => 'bank_transfer',
        ];
        foreach (array_keys($rows) as $code) {
            DB::table('inv_payment_modes')->insert([
                'payment_mode_name' => ucfirst($code), 'code' => $code, 'created_at' => now(), 'updated_at' => now(),
                // A soft-deleted row is typed too, so restoring it is safe.
                'deleted_at' => $code === 'card' ? now() : null,
            ]);
        }

        $migration->up();

        $this->assertSame($rows, DB::table('inv_payment_modes')->pluck('type', 'code')->all());
    }

    public function test_a_new_mode_needs_a_valid_type(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/payment-modes', $this->body(['type' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('type');

        $this->actingAs($this->user)->postJson('/api/v1/payment-modes', $this->body(['type' => 'barter']))
            ->assertUnprocessable()->assertJsonValidationErrors('type');

        $this->actingAs($this->user)->postJson('/api/v1/payment-modes', $this->body())
            ->assertCreated()
            ->assertJsonPath('data.type', 'bank_transfer')
            ->assertJsonPath('data.type_label', 'Bank Transfer')
            ->assertJsonPath('data.is_built_in', false);
    }

    public function test_a_built_in_mode_keeps_its_type_but_its_other_fields_can_change(): void
    {
        $cheque = PaymentMode::create([
            'payment_mode_name' => 'Cheque', 'code' => 'cheque', 'type' => PaymentModeType::Cheque, 'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/payment-modes/{$cheque->id}", $this->body(['payment_mode_name' => 'Cheque', 'code' => 'cheque', 'type' => 'cash']))
            ->assertUnprocessable()->assertJsonValidationErrors('type');

        // Renaming the code in the same request does not unlock it.
        $this->actingAs($this->user)
            ->putJson("/api/v1/payment-modes/{$cheque->id}", $this->body(['payment_mode_name' => 'Cheque', 'code' => 'chq', 'type' => 'cash']))
            ->assertUnprocessable()->assertJsonValidationErrors('type');

        $this->actingAs($this->user)
            ->putJson("/api/v1/payment-modes/{$cheque->id}", $this->body(['payment_mode_name' => 'Company Cheque', 'code' => 'cheque', 'type' => 'cheque']))
            ->assertOk()->assertJsonPath('data.payment_mode_name', 'Company Cheque');

        $this->assertSame(PaymentModeType::Cheque, $cheque->fresh()->type);
    }

    public function test_a_client_added_mode_can_change_type(): void
    {
        $ceft = PaymentMode::create([
            'payment_mode_name' => 'CEFT', 'code' => 'ceft', 'type' => PaymentModeType::Card, 'is_active' => true,
        ]);

        $this->actingAs($this->user)->putJson("/api/v1/payment-modes/{$ceft->id}", $this->body())
            ->assertOk()->assertJsonPath('data.type', 'bank_transfer');
    }

    public function test_a_row_without_a_type_falls_back_to_its_code(): void
    {
        $mode = new PaymentMode(['payment_mode_name' => 'Petty', 'code' => 'petty_cash']);

        $this->assertSame(PaymentModeType::Cash, $mode->effectiveType());
        $this->assertSame(PaymentModeType::BankTransfer, (new PaymentMode(['code' => 'slips']))->effectiveType());
    }
}
