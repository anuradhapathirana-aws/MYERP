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

        // The bootstrap super-admin, created ONLY when absent.
        //
        // firstOrCreate, not updateOrCreate. The previous version rewrote this
        // row on every deployment, which did two things nobody wanted on a
        // live server:
        //
        //   - reset the password back to the default, so a server was one
        //     unrelated deploy away from a publicly guessable super-admin
        //   - reset active_modules to ['inventory'], silently removing Finance
        //     from the very account used to administer it
        //
        // A bootstrap exists to make a fresh install reachable. Once someone
        // is using the system, it has no business touching their account.
        $admin = User::firstOrCreate(
            ['email' => 'admin@erp.local'],
            [
                'name'           => 'Admin User',
                'password'       => Hash::make('password'),
                'role'           => UserRole::SuperAdmin,
                'active_modules' => ['inventory'],
            ],
        );

        // assignRole, not syncRoles: syncRoles REPLACES every role, so it would
        // strip any additional role an administrator had granted. This only
        // adds what is missing.
        if (! $admin->hasRole('super_admin')) {
            $admin->assignRole('super_admin');
        }

        // Global settings (module toggles) — idempotent.
        $this->call(GlobalSettingSeeder::class);

        // Default payment modes (Cash/Cheque/Card/Setoff) — idempotent.
        $this->call(PaymentModeSeeder::class);
    }
}
