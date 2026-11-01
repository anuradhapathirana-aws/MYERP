<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank deposits: cash and received cheques taken to the bank on one slip.
 *
 * Confirming one posts Dr the Bank account / Cr each Cash Book the money sat
 * in (the cash book itself for notes, a cheque-accepting cash book -- "Cheques
 * in Hand" -- for cheques).
 *
 * company_id is a SOFT link (indexed, no FK), as on fin_bill_payments: the
 * companies table is inv_* and the Golden Rule forbids a hard key into it.
 * bank_account_id points at a Finance table, so it carries a real key.
 *
 * cash_total / cheque_total / total_amount are always the sum of the lines,
 * set by BankDepositService, never by the client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_bank_deposits', function (Blueprint $table): void {
            $table->id();

            $table->string('deposit_no', 30)->unique();
            $table->uuid('request_id')->nullable()->unique();

            $table->unsignedBigInteger('company_id')->index();
            $table->date('deposit_date')->index();

            $table->foreignId('bank_account_id')->constrained('fin_ledger_accounts');
            // The bank's own slip number, written on the paying-in slip.
            $table->string('slip_no', 50)->nullable();
            $table->text('description')->nullable();

            $table->char('currency', 3)->default('LKR');
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->decimal('cash_total', 15, 2)->default(0);
            $table->decimal('cheque_total', 15, 2)->default(0);
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
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_bank_deposits');
    }
};
