<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_supplier_payment_allocations', function (Blueprint $table): void {
            // Permanent write-off applied to this GRN row (reduces its outstanding for good)
            $table->decimal('discount', 15, 4)->default(0)->after('outstanding_before');
        });

        Schema::table('inv_supplier_payments', function (Blueprint $table): void {
            // Header aggregate of allocations.discount, for reporting/list display
            $table->decimal('discount_amount', 15, 4)->default(0)->after('gross_amount');
        });
    }

    public function down(): void
    {
        Schema::table('inv_supplier_payment_allocations', function (Blueprint $table): void {
            $table->dropColumn('discount');
        });

        Schema::table('inv_supplier_payments', function (Blueprint $table): void {
            $table->dropColumn('discount_amount');
        });
    }
};
