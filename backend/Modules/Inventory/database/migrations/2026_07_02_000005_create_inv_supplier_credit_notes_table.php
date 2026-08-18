<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_supplier_credit_notes', function (Blueprint $table): void {
            $table->id();

            // Supplier — soft link, no FK
            $table->unsignedBigInteger('supplier_id');

            // customer_return | over_payment | advance
            $table->string('credit_type', 20);

            $table->decimal('amount', 15, 4)->default(0);
            $table->decimal('remaining_balance', 15, 4)->default(0);
            $table->text('remark')->nullable();

            // open | exhausted
            $table->string('status', 20)->default('open');

            // Soft link back to the payment that created this credit note (audit trail)
            $table->unsignedBigInteger('source_payment_id')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index('supplier_id');
            $table->index('status');
            $table->index('credit_type');
            $table->index('source_payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_supplier_credit_notes');
    }
};
