<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_supplier_payments', function (Blueprint $table): void {
            $table->id();

            // Auto-generated document number (e.g. PAY-0001)
            $table->string('payment_no', 30)->unique();

            $table->date('payment_date');
            $table->date('transaction_date')->nullable();
            $table->string('reference_no', 100)->nullable();

            // Supplier — soft link, no FK (consistent with GRN's supplier_id)
            $table->string('supplier_type', 50)->nullable();
            $table->unsignedBigInteger('supplier_id');

            $table->text('payment_remark')->nullable();

            // Standalone advance (zero GRN allocations) support
            $table->boolean('is_advance')->default(false);
            $table->decimal('advance_amount', 15, 4)->default(0);

            // Server-computed totals — never trust client-submitted values at confirm time
            $table->decimal('gross_amount', 15, 4)->default(0);
            $table->decimal('setoff_amount', 15, 4)->default(0);
            $table->decimal('net_amount', 15, 4)->default(0);

            // Workflow status: draft | confirmed
            $table->string('status', 20)->default('draft');

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('supplier_id');
            $table->index('status');
            $table->index('payment_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_supplier_payments');
    }
};
