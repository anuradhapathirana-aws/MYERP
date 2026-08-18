<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable stock ledger — one row per product movement.
     * Records are never updated or deleted; corrections are made via reversal entries.
     */
    public function up(): void
    {
        Schema::create('inv_stock_transactions', function (Blueprint $table): void {
            $table->id();

            // When the movement happened
            $table->dateTime('transaction_date');

            // Polymorphic soft reference — what caused this movement
            $table->string('reference_type', 50);   // e.g. 'grn', 'adjustment', 'sale', 'transfer'
            $table->unsignedBigInteger('reference_id');

            // What moved — soft links
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();

            // Batch / serial info (if applicable)
            $table->string('batch_no', 100)->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->date('expiry_date')->nullable();

            // Movement quantities — only one of these should be non-zero per row
            $table->decimal('qty_in', 15, 4)->default(0);
            $table->decimal('qty_out', 15, 4)->default(0);

            // Unit & cost at time of transaction (for valuation)
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->decimal('unit_price', 15, 4)->nullable();

            // Who triggered the movement — soft link to users
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index(['product_id', 'store_id', 'location_id'], 'idx_stock_position');
            $table->index(['reference_type', 'reference_id'], 'idx_reference');
            $table->index('transaction_date');
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_stock_transactions');
    }
};
