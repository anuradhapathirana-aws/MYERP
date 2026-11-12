<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cheque the bank returned unpaid -- one row per cheque, from a customer
 * receipt (we received it) or a supplier payment (we issued it).
 *
 * The allocations say what the cheque had settled and is now reopened:
 * invoices or GRNs (the balance services subtract them), and the credit notes
 * the receipt or payment minted from it (their remaining balance fell).
 *
 * bank_account_id and the journal number are soft links to Finance: no FK
 * across modules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_cheque_returns', function (Blueprint $table): void {
            $table->id();
            // The settlement row that carried the cheque -- one cheque, returned once.
            $table->string('settlement_type', 40);
            $table->unsignedBigInteger('settlement_id');
            $table->string('document_type', 30);      // customer_receipt | supplier_payment
            $table->unsignedBigInteger('document_id');
            $table->string('document_no', 50);
            $table->string('party_type', 20);         // customer | supplier
            $table->unsignedBigInteger('party_id');
            $table->string('cheque_no', 50)->nullable();
            $table->date('cheque_date')->nullable();
            $table->decimal('amount', 15, 4);
            $table->date('return_date');
            $table->string('reference', 50);          // the Bank Reconciliation that found it
            $table->unsignedBigInteger('bank_account_id')->nullable();
            $table->string('journal_no', 30)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['settlement_type', 'settlement_id'], 'inv_cr_settlement_unique');
            $table->index(['document_type', 'document_id'], 'inv_cr_document_idx');
            $table->index(['party_type', 'party_id'], 'inv_cr_party_idx');
        });

        Schema::create('inv_cheque_return_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cheque_return_id')->constrained('inv_cheque_returns')->cascadeOnDelete();
            // invoice | grn | customer_credit_note | supplier_credit_note
            $table->string('reference_type', 30);
            $table->unsignedBigInteger('reference_id');
            $table->decimal('amount', 15, 4);
            $table->timestamps();

            $table->index(['reference_type', 'reference_id'], 'inv_cra_reference_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_cheque_return_allocations');
        Schema::dropIfExists('inv_cheque_returns');
    }
};
