<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_stock_reconciliations', function (Blueprint $table): void {
            $table->id();

            // Auto-generated document number (e.g. SR-2026-0001)
            $table->string('reconciliation_no', 30)->unique();

            // What is being corrected — soft links, same convention as every other
            // document line referencing product/store/location/unit
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('attribute_id')->nullable();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();

            // Quantities in the product's base (stocking) UOM.
            // system_qty_base is a display-only snapshot taken at draft time — approve()
            // always recomputes the live balance and the true variance against it, so the
            // posted correction is never stale even if stock moved while this was pending.
            $table->decimal('system_qty_base', 20, 6)->default(0);
            $table->decimal('counted_qty_base', 20, 6)->default(0);
            $table->decimal('variance_qty_base', 20, 6)->default(0);

            // Generic soft link to the document this correction relates to
            // (e.g. source_type = 'grn', source_id = the GRN's id). Nullable — a plain
            // stock count with no originating document is a valid reconciliation too.
            $table->string('source_type', 30)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            // When true and source_type = 'grn', approving this also corrects the
            // linked PO item's quantity_received by the same delta.
            $table->boolean('also_adjust_po')->default(false);

            $table->string('reason', 255);
            $table->text('remarks')->nullable();

            // Workflow status
            $table->string('status', 20)->default('draft');
            // Values: draft | pending_approval | approved | rejected

            // Audit — soft links to users table
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes for common filter queries
            $table->index('status');
            $table->index('product_id');
            $table->index('store_id');
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_stock_reconciliations');
    }
};
