<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_customer_credit_notes', function (Blueprint $table): void {
            $table->id();

            // Auto-generated document number (e.g. CCN-0001)
            $table->string('credit_note_no', 30)->nullable()->unique();

            // Customer — soft link, no FK
            $table->unsignedBigInteger('customer_id');

            // sales_return | over_payment | advance
            $table->string('credit_type', 20);

            $table->decimal('amount', 15, 4)->default(0);
            $table->decimal('remaining_balance', 15, 4)->default(0);
            $table->text('remark')->nullable();

            // open | exhausted
            $table->string('status', 20)->default('open');

            // Soft link back to the receipt that created this credit note (audit trail)
            $table->unsignedBigInteger('source_receipt_id')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index('customer_id');
            $table->index('status');
            $table->index('credit_type');
            $table->index('source_receipt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_customer_credit_notes');
    }
};
