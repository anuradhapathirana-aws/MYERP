<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments that settle supplier bills (fin_bills).
 *
 * Confirming one posts Dr Accounts Payable (one line per bill, party =
 * supplier, source = bill) / Cr the cash book or bank it was paid from.
 *
 * supplier_id and company_id are SOFT links (indexed, no FK) for the same
 * reason as on fin_bills: those tables are core/inv_* and the Golden Rule
 * forbids a hard key from Finance into them. pay_from_account_id points at a
 * Finance table, so it carries a real key.
 *
 * payment_mode_id is a SOFT link to the shared core Payment Modes master
 * (inv_payment_modes, created by the Inventory module's migrations, hence no
 * FK). The mode's name and code are snapshotted so renaming or deactivating a
 * mode later never rewrites an old payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_bill_payments', function (Blueprint $table): void {
            $table->id();

            $table->string('payment_no', 30)->unique();
            $table->uuid('request_id')->nullable()->unique();

            $table->unsignedBigInteger('supplier_id')->index();
            $table->unsignedBigInteger('company_id')->index();

            $table->date('payment_date')->index();
            $table->string('reference_no', 100)->nullable();

            $table->foreignId('pay_from_account_id')->constrained('fin_ledger_accounts');
            $table->unsignedBigInteger('payment_mode_id')->index();
            $table->string('payment_mode_name', 50);
            $table->string('payment_mode_code', 30);
            $table->string('cheque_no', 30)->nullable();
            $table->date('cheque_date')->nullable();

            $table->text('description')->nullable();

            $table->char('currency', 3)->default('LKR');
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->decimal('total_amount', 15, 2)->default(0);

            $table->string('status', 20)->default('draft')->index();

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

            // A cheque number may be used once per bank account (non-voided).
            $table->index(['pay_from_account_id', 'cheque_no'], 'fin_bill_payments_cheque_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_bill_payments');
    }
};
