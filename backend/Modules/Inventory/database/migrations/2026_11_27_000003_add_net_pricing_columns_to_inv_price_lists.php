<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['inv_product_sales_channels', 'inv_product_variant_prices'];

    /**
     * Price-list selling prices become BEFORE TAX.
     *
     *  - selling_price widened to 8 dp: a Before Tax price derived by ÷ 1.18 needs the
     *    tail so that × 1.18 lands back on the old After Tax price to the cent
     *  - legacy_selling_price / net_price_source: the old value and how the new one was
     *    derived (costing = exact, vat_stripped = ÷ VAT, for the client to verify)
     *  - variant rows gain cost_price — the variant's own landed cost from its costing
     */
    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->decimal('selling_price', 20, 8)->nullable()->change();

                if (! Schema::hasColumn($table, 'legacy_selling_price')) {
                    $blueprint->decimal('legacy_selling_price', 20, 8)->nullable()->after('selling_price');
                }
                if (! Schema::hasColumn($table, 'net_price_source')) {
                    $blueprint->string('net_price_source', 20)->nullable()->after('legacy_selling_price');
                }
            });
        }

        if (! Schema::hasColumn('inv_product_variant_prices', 'cost_price')) {
            Schema::table('inv_product_variant_prices', function (Blueprint $table): void {
                $table->decimal('cost_price', 20, 8)->nullable()->after('sales_channel_id');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn(['legacy_selling_price', 'net_price_source']);
                $blueprint->decimal('selling_price', 15, 4)->nullable()->change();
            });
        }

        Schema::table('inv_product_variant_prices', function (Blueprint $table): void {
            $table->dropColumn('cost_price');
        });
    }
};
