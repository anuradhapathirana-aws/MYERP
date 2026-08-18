<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_costings', function (Blueprint $table): void {
            $table->id();

            // Auto-generated document number (e.g. CST-2026-0001)
            $table->string('document_no', 30)->unique();

            // Auto-generated reference number (e.g. CRef-0001)
            $table->string('reference_no', 30)->unique();

            // Soft link to inv_supplier_masters
            $table->unsignedBigInteger('supplier_id');

            // 'fob' | 'cif'
            $table->string('costing_type', 10);

            // Quantities auto-summed from selected GRNs
            $table->decimal('total_items', 15, 4)->default(0);

            // User-supplied material cost
            $table->decimal('material_cost', 15, 4)->default(0);

            // Header fields
            $table->string('bill_of_lading', 100)->nullable();
            $table->date('expected_date')->nullable();
            $table->date('transaction_date')->nullable();
            $table->text('note')->nullable();

            // ── Calculated summary (stored for reporting performance) ──────────
            $table->decimal('total_additional_expenses', 15, 4)->default(0);
            $table->decimal('raw_material_cost', 15, 4)->default(0);
            $table->decimal('total_landed_cost', 15, 4)->default(0);

            $table->decimal('value_addition_pct', 5, 2)->default(10.00);
            $table->decimal('value_addition_amount', 15, 4)->default(0);

            $table->decimal('fob_cif_cost', 15, 4)->default(0);

            $table->decimal('sscl_pct', 5, 2)->default(2.50);
            $table->decimal('sscl_amount', 15, 4)->default(0);

            $table->decimal('gross_fob_cif_value', 15, 4)->default(0);

            $table->decimal('vat_pct', 5, 2)->default(18.00);
            $table->decimal('vat_amount', 15, 4)->default(0);

            $table->decimal('total_price_with_vat', 15, 4)->default(0);
            // ─────────────────────────────────────────────────────────────────

            $table->string('status', 20)->default('draft'); // draft | confirmed
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('supplier_id');
            $table->index('costing_type');
            $table->index('status');
            $table->index('transaction_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_costings');
    }
};
