<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proforma-first flow: a proforma is raised before any sales order (any product,
 * in stock or not) and later recalled into one or more SOs.
 *
 *  - Proforma lines snapshot their stock situation when issued (informational).
 *  - SO lines carry a soft link to the proforma line they were recalled from —
 *    per LINE, so one proforma can feed several SOs and a roll-split SO line
 *    still bills the quoted price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_proforma_invoice_items', function (Blueprint $table): void {
            $table->string('stock_status', 20)->nullable()->after('line_total'); // in_stock | partial | non_stock
            $table->decimal('available_qty', 15, 4)->nullable()->after('stock_status');
        });

        Schema::table('inv_sales_order_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('proforma_item_id')->nullable()->after('so_id');
            $table->index('proforma_item_id');
        });

        // Proformas raised from an SO under the earlier flow already know their SO
        // line — record the link on the SO side so both flows share one mechanism.
        $links = DB::table('inv_proforma_invoice_items as pi')
            ->join('inv_proforma_invoices as p', 'p.id', '=', 'pi.proforma_id')
            ->whereNull('p.deleted_at')
            ->where('p.status', '!=', 'cancelled')
            ->whereNotNull('pi.so_item_id')
            ->pluck('pi.id', 'pi.so_item_id');

        foreach ($links as $soItemId => $proformaItemId) {
            DB::table('inv_sales_order_items')
                ->where('id', $soItemId)
                ->whereNull('proforma_item_id')
                ->update(['proforma_item_id' => $proformaItemId]);
        }
    }

    public function down(): void
    {
        Schema::table('inv_sales_order_items', function (Blueprint $table): void {
            $table->dropIndex(['proforma_item_id']);
            $table->dropColumn('proforma_item_id');
        });

        Schema::table('inv_proforma_invoice_items', function (Blueprint $table): void {
            $table->dropColumn(['stock_status', 'available_qty']);
        });
    }
};
