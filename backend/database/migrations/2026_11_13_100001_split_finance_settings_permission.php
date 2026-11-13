<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Data migration: manage_finance_settings used to cover mappings, posting
 * mode, party ledger mode, maker-checker AND the period lock. The first four
 * are now super admin only (no permission), so the old permission is RENAMED
 * to manage_finance_period_lock — every role and user that held it keeps the
 * one part still grantable (closing a month) with no reassignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rename('manage_finance_settings', 'manage_finance_period_lock');
    }

    public function down(): void
    {
        $this->rename('manage_finance_period_lock', 'manage_finance_settings');
    }

    private function rename(string $from, string $to): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $old = Permission::where('name', $from)->where('guard_name', 'web')->first();

        if ($old === null) {
            return;
        }

        $new = Permission::where('name', $to)->where('guard_name', 'web')->first();

        if ($new === null) {
            $old->update(['name' => $to]);
        } else {
            // The seeder already created the target (it ran first on this
            // deployment): carry every holder across, then drop the old row.
            foreach ($old->roles as $role) {
                $role->givePermissionTo($new);
            }
            foreach ($old->users as $user) {
                $user->givePermissionTo($new);
            }
            $old->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
