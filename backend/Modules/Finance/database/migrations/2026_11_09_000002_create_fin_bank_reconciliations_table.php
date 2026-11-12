<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank reconciliations: one bank account matched against one bank statement.
 *
 * The book side is never copied here. Every bank movement is already a line in
 * fin_journal_lines; a reconciliation only records which of those lines the
 * statement shows (fin_bank_reconciliation_lines). Posting it moves no money:
 * it freezes the matching, and the statement balance becomes the next
 * reconciliation's opening balance.
 *
 *   closing_balance = opening_balance + cleared_deposits - cleared_withdrawals
 *   difference      = statement_balance - closing_balance   (must be 0 to post)
 *
 * book_balance is the ledger balance of the account on statement_date, kept at
 * post time for the reconciliation statement (book balance + unpresented
 * cheques - deposits in transit = bank balance).
 *
 * company_id is a SOFT link (inv_companies, hard delete), as on every Finance
 * document. bank_account_id points at a Finance table, so it carries a real key.
 *
 * Lifecycle: draft -> posted -> voided (undone). Only the latest posted
 * reconciliation of an account can be undone, and one account has at most one
 * draft at a time -- both enforced by BankReconciliationService under a lock on
 * the bank account row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_bank_reconciliations', function (Blueprint $table): void {
            $table->id();

            $table->string('recon_no', 30)->unique();
            $table->uuid('request_id')->nullable()->unique();

            $table->unsignedBigInteger('company_id')->index();
            $table->foreignId('bank_account_id')->constrained('fin_ledger_accounts');

            $table->date('date_from')->nullable();
            $table->date('statement_date')->index();

            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('statement_balance', 15, 2)->default(0);
            $table->decimal('cleared_deposits', 15, 2)->default(0);
            $table->decimal('cleared_withdrawals', 15, 2)->default(0);
            $table->decimal('closing_balance', 15, 2)->default(0);
            $table->decimal('difference', 15, 2)->default(0);
            $table->decimal('book_balance', 15, 2)->nullable();

            $table->text('notes')->nullable();

            $table->string('status', 20)->default('draft')->index();

            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();

            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason', 500)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['bank_account_id', 'status', 'statement_date'], 'fin_br_acct_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_bank_reconciliations');
    }
};
