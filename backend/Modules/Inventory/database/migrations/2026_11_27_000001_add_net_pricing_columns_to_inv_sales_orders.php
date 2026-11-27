<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SO lines now hold the BEFORE TAX price plus the VAT % the invoice will add.
     *
     *  - vat_pct             frozen rate per line (null only until the backfill runs)
     *  - net_price_source    how the backfill derived the Before Tax price (audit)
     *  - legacy_*            the line's values before the switch — for the dry-run
     *                        report and for down() of the conversion migration
     *  - vat_amount (header) the VAT the invoice will add; grand_total includes it
     */
    public function up(): void
    {
        Schema::table('inv_sales_order_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('inv_sales_order_items', 'vat_pct')) {
                $table->decimal('vat_pct', 5, 2)->nullable()->after('tax');
            }
            if (! Schema::hasColumn('inv_sales_order_items', 'net_price_source')) {
                $table->string('net_price_source', 20)->nullable()->after('vat_pct');
            }
            if (! Schema::hasColumn('inv_sales_order_items', 'legacy_unit_price')) {
                $table->decimal('legacy_unit_price', 20, 8)->nullable()->after('net_price_source');
                $table->decimal('legacy_tax', 8, 4)->nullable()->after('legacy_unit_price');
                $table->decimal('legacy_line_total', 15, 4)->nullable()->after('legacy_tax');
            }
        });

        Schema::table('inv_sales_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('inv_sales_orders', 'vat_amount')) {
                $table->decimal('vat_amount', 15, 4)->default(0)->after('transport_charge');
            }
            if (! Schema::hasColumn('inv_sales_orders', 'legacy_grand_total')) {
                $table->decimal('legacy_grand_total', 15, 4)->nullable()->after('grand_total');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inv_sales_order_items', function (Blueprint $table): void {
            $table->dropColumn(['vat_pct', 'net_price_source', 'legacy_unit_price', 'legacy_tax', 'legacy_line_total']);
        });

        Schema::table('inv_sales_orders', function (Blueprint $table): void {
            $table->dropColumn(['vat_amount', 'legacy_grand_total']);
        });
    }
};
