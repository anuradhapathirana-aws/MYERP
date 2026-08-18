<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_customer_receipt_setoffs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('receipt_id')
                ->constrained('inv_customer_receipts')
                ->cascadeOnDelete();

            // sales_return | over_payment | advance
            $table->string('setoff_type', 20);

            // Nullable: a 'sales_return' setoff creates its credit note during confirm(),
            // so it has no credit_note_id yet at draft time.
            $table->foreignId('credit_note_id')
                ->nullable()
                ->constrained('inv_customer_credit_notes')
                ->nullOnDelete();

            $table->decimal('amount', 15, 4)->default(0);
            $table->text('remark')->nullable();

            $table->timestamps();

            $table->index('receipt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_customer_receipt_setoffs');
    }
};
