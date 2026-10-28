<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier bills for expenses and services (rent, utilities, fees ...).
 *
 * Confirming one posts Dr expense / Cr Accounts Payable (party = supplier).
 *
 * supplier_id, company_id and location_id are SOFT links (indexed, no FK): the
 * supplier, company and location tables are named inv_* and the Golden Rule
 * forbids a hard key from Finance into them.
 *
 * Columns reserved for later features, so neither needs a schema change:
 *   - paid_amount / payment_status : written only by Bill Payment.
 *   - tax_amount                   : filled once the Tax Master exists.
 *   - currency / exchange_rate     : LKR / 1 until the UI offers currencies.
 *
 * request_id makes create idempotent: a double-clicked Save sends the same
 * UUID twice and the second request returns the first bill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_bills', function (Blueprint $table): void {
            $table->id();

            $table->string('bill_no', 30)->unique();
            $table->uuid('request_id')->nullable()->unique();

            $table->unsignedBigInteger('supplier_id')->index();

            $table->date('transaction_date')->index();
            $table->string('document_no', 100);
            $table->string('document_no_normalized', 100);
            $table->date('document_date')->nullable();
            $table->date('due_date')->index();

            $table->char('currency', 3)->default('LKR');
            $table->decimal('exchange_rate', 18, 6)->default(1);

            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('location_id')->nullable()->index();

            $table->text('description')->nullable();

            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->nullable();
            $table->decimal('paid_amount', 15, 2)->default(0);

            $table->string('status', 20)->default('draft')->index();
            $table->string('payment_status', 20)->default('unpaid')->index();

            $table->string('journal_no', 30)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();

            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->date('reversal_date')->nullable();
            $table->string('reversal_journal_no', 30)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['supplier_id', 'document_no_normalized'], 'fin_bills_supplier_doc_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_bills');
    }
};
