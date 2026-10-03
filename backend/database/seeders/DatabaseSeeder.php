<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * PRODUCTION-SAFE seeder. Everything called here is either reference data or
 * idempotent configuration, so it can be re-run on a live database on every
 * deployment without touching the client's own records.
 *
 * Sample/demo records (fake suppliers, companies, locations, employees) live in
 * DemoDataSeeder and are deliberately NOT called from here. Run those by name on
 * a fresh development machine:
 *
 *     php artisan db:seed --class=DemoDataSeeder
 *
 * Reference data that must exist on every install (supplier groups, the bank
 * list) is delivered by MIGRATIONS instead of seeders, so `php artisan migrate`
 * alone is enough on a deployment and there is no seeder step to forget.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Roles and permissions must be seeded before users so assignRole()
        // works. This seeder is additive — it never revokes a permission an
        // administrator granted through the UI.
        $this->call(RolesAndPermissionsSeeder::class);

        // The bootstrap super-admin. updateOrCreate keyed on email, so an
        // existing live account is never duplicated.
        //
        // NOTE: this resets the password of admin@erp.local on every run. On a
        // live server, change that account's password after first setup, or
        // remove this block from the deployment sequence.
        $admin = User::updateOrCreate(
            ['email' => 'admin@erp.local'],
            [
                'name'           => 'Admin User',
                'password'       => Hash::make('password'),
                'role'           => UserRole::SuperAdmin,
                'active_modules' => ['inventory'],
            ],
        );

        // Ensure the Spatie role is always in sync even on re-runs
        $admin->syncRoles(['super_admin']);

        // Global settings (module toggles) — idempotent.
        $this->call(GlobalSettingSeeder::class);

        // Default payment modes (Cash/Cheque/Card/Setoff) — idempotent.
        $this->call(PaymentModeSeeder::class);
    }
}
