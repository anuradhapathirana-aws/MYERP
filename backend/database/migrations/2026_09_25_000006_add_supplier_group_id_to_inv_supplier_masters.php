<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the supplier group link. Nullable at the DB level so it can be applied to
 * a populated table; "required" is enforced by SupplierMasterRequest.
 *
 * No foreign key: this matches the project's existing convention for links that
 * cross an ownership boundary (see inv_locations.company_id), and SQLite cannot
 * add a FK to an existing table without a full rebuild.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_supplier_masters', function (Blueprint $table): void {
            $table->unsignedBigInteger('supplier_group_id')
                ->nullable()
                ->after('reference_no')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('inv_supplier_masters', function (Blueprint $table): void {
            $table->dropIndex(['supplier_group_id']);
            $table->dropColumn('supplier_group_id');
        });
    }
};
