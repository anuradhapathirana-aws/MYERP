<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-product landed-cost breakdown of a costing. One row per GRN item line
 * of the selected GRNs, storing the full per-unit price build-up so the user
 * can see exactly how the selling price was formed:
 *
 *   unit_price (GRN purchase) + charge_portion = landed_unit_cost
 *   + margin_amount (→ ±SSCL / ±VAT when toggled) = selling_price
 *
 * All monetary columns are PER UNIT. Soft links only — no cross-module FKs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_costing_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('costing_id')->index();
            $table->unsignedBigInteger('grn_id')->index();
            $table->unsignedBigInteger('grn_item_id');

            // Snapshots from the GRN line at build time
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('attribute_id')->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->decimal('unit_price', 15, 4)->default(0); // GRN purchasing price

            // Per-unit price build-up
            $table->decimal('charge_portion', 15, 4)->default(0);   // Σexpenses ÷ Σqty
            $table->decimal('landed_unit_cost', 15, 4)->default(0); // unit_price + portion
            $table->decimal('margin_pct', 8, 4)->nullable();        // null = costing default
            $table->decimal('margin_amount', 15, 4)->default(0);
            $table->decimal('sscl_amount', 15, 4)->default(0);
            $table->decimal('vat_amount', 15, 4)->default(0);
            $table->decimal('selling_price', 15, 4)->default(0);
            $table->boolean('is_price_overridden')->default(false); // user typed the final price

            $table->timestamps();

            // A GRN line appears at most once per costing (rows are rebuilt on edit)
            $table->unique(['costing_id', 'grn_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_costing_items');
    }
};
