<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Roles and permissions must be seeded before users so assignRole() works
        $this->call(RolesAndPermissionsSeeder::class);

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

        // Seed all global settings (module toggles) via dedicated seeder
        $this->call(GlobalSettingSeeder::class);

        // Seed default payment modes (Cash/Cheque/Card/Setoff)
        $this->call(PaymentModeSeeder::class);
    }
}
