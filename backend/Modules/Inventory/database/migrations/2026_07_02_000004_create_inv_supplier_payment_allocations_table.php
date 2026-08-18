<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_supplier_payment_allocations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('payment_id')
                ->constrained('inv_supplier_payments')
                ->cascadeOnDelete();

            // Generic soft link to the payable document — today always ('grn', grn.id).
            // No DB FK to inv_goods_received_notes so this stays extensible to future document types.
            $table->string('reference_type', 30)->default('grn');
            $table->unsignedBigInteger('reference_id');

            // Denormalized snapshots for display/history even if the source GRN changes later
            $table->date('grn_date')->nullable();
            $table->string('po_no', 30)->nullable();
            $table->string('reference_no', 100)->nullable();
            $table->decimal('grn_amount', 15, 4)->default(0);

            // Editable due date (client defaults it to grn_date + supplier.credit_period)
            $table->date('due_date')->nullable();

            // Informational snapshot only — outstanding is always recomputed live at confirm time
            $table->decimal('outstanding_before', 15, 4)->default(0);

            $table->decimal('payment_amount', 15, 4)->default(0);
            $table->text('line_remark')->nullable();

            $table->timestamps();

            $table->index(['reference_type', 'reference_id'], 'inv_sp_allocations_ref_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_supplier_payment_allocations');
    }
};
