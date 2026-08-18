<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_customer_receipt_allocations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('receipt_id')
                ->constrained('inv_customer_receipts')
                ->cascadeOnDelete();

            // Generic soft link to the receivable document — today always ('invoice', invoice.id).
            // No DB FK to inv_invoices so this stays extensible to future document types.
            $table->string('reference_type', 30)->default('invoice');
            $table->unsignedBigInteger('reference_id');

            // Denormalized snapshots for display/history even if the source invoice changes later
            $table->date('invoice_date')->nullable();
            $table->string('so_no', 30)->nullable();
            $table->string('do_no', 30)->nullable();
            $table->decimal('invoice_amount', 15, 4)->default(0);

            // Snapshot of the invoice's own due date at allocation time
            $table->date('due_date')->nullable();

            // Informational snapshot only — outstanding is always recomputed live at confirm time
            $table->decimal('outstanding_before', 15, 4)->default(0);

            // Permanent write-off applied to this invoice row (reduces its outstanding for good)
            $table->decimal('discount', 15, 4)->default(0);

            $table->decimal('receipt_amount', 15, 4)->default(0);
            $table->text('line_remark')->nullable();

            $table->timestamps();

            $table->index(['reference_type', 'reference_id'], 'inv_cr_allocations_ref_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_customer_receipt_allocations');
    }
};
