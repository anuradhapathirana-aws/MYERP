<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which bills a payment settles, and how much of each.
 *
 * Both sides are Finance tables, so both carry real keys. A bill with
 * allocations cannot be hard-deleted (it is soft-deleted as a draft only, and
 * only confirmed bills can be allocated to).
 *
 * balance_before is the bill's open balance at the moment the payment was
 * confirmed: a snapshot for the audit trail and the printed voucher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_bill_payment_allocations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('bill_payment_id')->constrained('fin_bill_payments')->cascadeOnDelete();
            $table->foreignId('bill_id')->constrained('fin_bills');
            $table->unsignedSmallInteger('line_no');

            $table->decimal('amount', 15, 2);
            $table->decimal('balance_before', 15, 2)->nullable();

            $table->timestamps();

            $table->unique(['bill_payment_id', 'bill_id'], 'fin_bill_payment_alloc_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_bill_payment_allocations');
    }
};
