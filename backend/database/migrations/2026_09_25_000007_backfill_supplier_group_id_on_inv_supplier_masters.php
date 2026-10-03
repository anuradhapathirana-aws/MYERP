<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Point every existing supplier at "Trade (Local)".
 *
 * All pre-existing supplier_type values were materials classifications
 * (Grey Fabric, Yarn, Dyes & Chemicals, Packing Materials, Machinery & Spares)
 * — i.e. what the supplier supplies, not an accounting group — so they all map
 * to the same accounting group. Staff reassign the handful that differ.
 *
 * Kept separate from the add-column and drop-column migrations so the data step
 * is auditable and independently reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        $groupId = DB::table('core_supplier_groups')->where('code', 'TRADE_LOCAL')->value('id');

        // Defensive: the seeding migration should have created this, but never
        // silently backfill NULLs if it somehow did not.
        if ($groupId === null) {
            $now = now();
            $groupId = DB::table('core_supplier_groups')->insertGetId([
                'code'       => 'TRADE_LOCAL',
                'name'       => 'Trade (Local)',
                'is_active'  => true,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('inv_supplier_masters')
            ->whereNull('supplier_group_id')
            ->update(['supplier_group_id' => $groupId]);
    }

    public function down(): void
    {
        DB::table('inv_supplier_masters')->update(['supplier_group_id' => null]);
    }
};
