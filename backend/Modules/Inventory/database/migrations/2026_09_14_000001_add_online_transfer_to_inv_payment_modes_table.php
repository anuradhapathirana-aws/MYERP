<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Data migration — deploy.sh never runs PaymentModeSeeder, so existing client databases
     * only receive the new Online Transfer pay mode through this migration.
     */
    public function up(): void
    {
        // Skip if a client admin already created a mode with this code or name (both columns are unique)
        $exists = DB::table('inv_payment_modes')
            ->where('code', 'online_transfer')
            ->orWhere('payment_mode_name', 'Online Transfer')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('inv_payment_modes')->insert([
            'payment_mode_name'     => 'Online Transfer',
            'code'                  => 'online_transfer',
            'requires_bank_details' => true,
            'requires_reference_no' => true,
            'requires_date'         => true,
            'sort_order'            => 5,
            'is_active'             => true,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);
    }

    public function down(): void
    {
        // Settlement lines keep their own payment_mode_code/name snapshots, so history stays readable
        DB::table('inv_payment_modes')->where('code', 'online_transfer')->delete();
    }
};
