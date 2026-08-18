<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_supplier_payment_setoffs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('payment_id')
                ->constrained('inv_supplier_payments')
                ->cascadeOnDelete();

            // customer_return | over_payment | advance
            $table->string('setoff_type', 20);

            // Nullable: a 'customer_return' setoff creates its credit note during confirm(),
            // so it has no credit_note_id yet at draft time.
            $table->foreignId('credit_note_id')
                ->nullable()
                ->constrained('inv_supplier_credit_notes')
                ->nullOnDelete();

            $table->decimal('amount', 15, 4)->default(0);
            $table->text('remark')->nullable();

            $table->timestamps();

            $table->index('payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_supplier_payment_setoffs');
    }
};
