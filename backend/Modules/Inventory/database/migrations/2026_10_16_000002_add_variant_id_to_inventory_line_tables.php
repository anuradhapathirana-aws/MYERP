<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every table that carries a line's colour (attribute_id) also carries its
     * variant. Soft link, no FK — the same rule as attribute_id on the ledger:
     * a movement must survive its master row. NULL = roll product, or a
     * product without variants — exactly today's behaviour.
     */
    private const TABLES = [
        'inv_purchase_request_items',
        'inv_purchase_order_items',
        'inv_goods_received_note_items',
        'inv_sales_order_items',
        'inv_delivery_order_items',
        'inv_invoice_items',
        'inv_proforma_invoice_items',
        'inv_customer_return_items',
        'inv_costing_items',
        'inv_stock_reconciliations',
        'inv_stock_transactions',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'variant_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->unsignedBigInteger('variant_id')->nullable()->after('attribute_id');
                $blueprint->index('variant_id', "idx_{$this->short($table)}_variant");
            });
        }

        // Guarded: MySQL DDL is not transactional, so a re-run after a partial run must not fail here.
        if (! Schema::hasIndex('inv_stock_transactions', 'idx_stock_product_variant')) {
            Schema::table('inv_stock_transactions', function (Blueprint $table): void {
                $table->index(['product_id', 'variant_id', 'store_id', 'location_id'], 'idx_stock_product_variant');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('inv_stock_transactions', 'variant_id')) {
            Schema::table('inv_stock_transactions', function (Blueprint $table): void {
                $table->dropIndex('idx_stock_product_variant');
            });
        }

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'variant_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropIndex("idx_{$this->short($table)}_variant");
                $blueprint->dropColumn('variant_id');
            });
        }
    }

    /** Index names must stay under MySQL's 64-char limit. */
    private function short(string $table): string
    {
        return str_replace(['inv_', '_items', 'goods_received_note', 'proforma_invoice'], ['', '_itm', 'grn', 'pfi'], $table);
    }
};
