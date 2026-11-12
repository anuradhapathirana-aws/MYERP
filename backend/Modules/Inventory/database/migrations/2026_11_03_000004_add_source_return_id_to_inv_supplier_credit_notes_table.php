<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_supplier_credit_notes', function (Blueprint $table): void {
            // Soft link to the supplier return that raised a supplier_return credit note
            $table->unsignedBigInteger('source_return_id')->nullable()->after('source_payment_id');
            $table->index('source_return_id');
        });
    }

    public function down(): void
    {
        Schema::table('inv_supplier_credit_notes', function (Blueprint $table): void {
            $table->dropIndex(['source_return_id']);
            $table->dropColumn('source_return_id');
        });
    }
};
