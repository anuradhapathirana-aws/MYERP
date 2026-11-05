<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tax code a new GRN line starts with for this supplier. Empty means
 * "no tax" — exactly how every GRN behaves today — so nothing changes on
 * deployment until an administrator sets it supplier by supplier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_supplier_masters', function (Blueprint $table): void {
            $table->unsignedBigInteger('default_tax_code_id')->nullable()->after('tax_regis_no')->index();
        });
    }

    public function down(): void
    {
        Schema::table('inv_supplier_masters', function (Blueprint $table): void {
            $table->dropIndex(['default_tax_code_id']);
            $table->dropColumn('default_tax_code_id');
        });
    }
};
