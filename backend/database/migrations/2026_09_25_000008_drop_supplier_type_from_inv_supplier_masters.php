<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the legacy free-text supplier_type column, now replaced by
 * supplier_group_id.
 *
 * NOTE: inv_supplier_payments.supplier_type is a different column and is
 * deliberately left alone — it is a historical snapshot of the group name at
 * payment time, and posted accounting documents must keep showing what was
 * true when they were posted.
 *
 * Isolated in its own migration so it can be skipped independently if a target
 * environment runs SQLite < 3.35 (dropColumn support).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inv_supplier_masters', 'supplier_type')) {
            return;
        }

        Schema::table('inv_supplier_masters', function (Blueprint $table): void {
            $table->dropColumn('supplier_type');
        });
    }

    public function down(): void
    {
        Schema::table('inv_supplier_masters', function (Blueprint $table): void {
            $table->string('supplier_type', 50)->nullable()->after('reference_no');
        });
    }
};
