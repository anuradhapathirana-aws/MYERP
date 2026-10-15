<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft links from an invoice (and its lines) back to the proforma it was
 * auto-linked to. Nullable — invoices without a proforma are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('proforma_id')->nullable()->after('do_id');
            $table->index('proforma_id');
        });

        Schema::table('inv_invoice_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('proforma_item_id')->nullable()->after('do_item_id');
            $table->index('proforma_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('inv_invoice_items', function (Blueprint $table): void {
            $table->dropIndex(['proforma_item_id']);
            $table->dropColumn('proforma_item_id');
        });

        Schema::table('inv_invoices', function (Blueprint $table): void {
            $table->dropIndex(['proforma_id']);
            $table->dropColumn('proforma_id');
        });
    }
};
