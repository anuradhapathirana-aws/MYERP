<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Product variants — one row per sellable combination of attribute values
     * (Red / Floral / M) of a quantity-tracked product. Stock, prices, landed
     * cost and every document line are keyed by the variant; roll-tracked
     * products never get variants.
     *
     * Prices live in their own table rather than as extra rows on
     * inv_product_sales_channels: that pivot is sync()'d by channel id from the
     * Product form, which would wipe or overwrite any variant rows parked there.
     */
    public function up(): void
    {
        if (! Schema::hasTable('inv_product_variants')) {
            Schema::create('inv_product_variants', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('product_id')
                    ->constrained('inv_products')
                    ->cascadeOnDelete();
                $table->string('sku', 80)->unique();
                $table->string('barcode', 80)->nullable()->unique();
                // Cached "Red / Floral / M" — what documents, PDFs and reports print.
                $table->string('variant_label', 255)->default('');
                // Sorted attribute ids joined by '-' ('' = the Default variant): blocks duplicate combinations.
                $table->string('combination_hash', 191)->default('');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['product_id', 'combination_hash'], 'uq_variant_product_combination');
            });
        }

        if (! Schema::hasTable('inv_product_variant_values')) {
            Schema::create('inv_product_variant_values', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('variant_id')
                    ->constrained('inv_product_variants')
                    ->cascadeOnDelete();
                // Restrict: an attribute value a variant is built from cannot be deleted from under it.
                $table->foreignId('attribute_type_id')
                    ->constrained('inv_attribute_types')
                    ->restrictOnDelete();
                $table->foreignId('attribute_id')
                    ->constrained('inv_attributes')
                    ->restrictOnDelete();
                $table->timestamps();

                $table->unique(['variant_id', 'attribute_type_id'], 'uq_variant_value_type');
                $table->index('attribute_id', 'idx_variant_value_attribute');
            });
        }

        if (! Schema::hasTable('inv_product_variant_prices')) {
            Schema::create('inv_product_variant_prices', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('variant_id')
                    ->constrained('inv_product_variants')
                    ->cascadeOnDelete();
                $table->foreignId('sales_channel_id')
                    ->constrained('inv_sales_channels')
                    ->restrictOnDelete();
                // Filed in the same unit as the product's own price-list row for this channel.
                $table->decimal('selling_price', 15, 4)->nullable();
                $table->decimal('wholesale_price', 15, 4)->nullable();
                $table->decimal('min_price', 15, 4)->nullable();
                $table->decimal('max_price', 15, 4)->nullable();
                $table->timestamps();

                $table->unique(['variant_id', 'sales_channel_id'], 'uq_variant_price_channel');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_product_variant_prices');
        Schema::dropIfExists('inv_product_variant_values');
        Schema::dropIfExists('inv_product_variants');
    }
};
