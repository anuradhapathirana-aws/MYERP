<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which bank lines of the ledger a reconciliation matched to the statement.
 *
 * journal_line_id is a real key: same module. restrictOnDelete because only
 * posted (immutable) journals can be matched, so the line can never go away.
 *
 *   status  realized       on the statement: cleared
 *           returned       on the statement and bounced back (Phase 3 posts the
 *                          bounce entry; return_journal_no records it)
 *           not_presented  explicitly not on the statement yet
 *
 * A line counts as cleared once it is realized or returned on a POSTED
 * reconciliation. "Cleared once" cannot be a unique index -- voided and
 * draft reconciliations keep their rows -- so BankReconciliationService
 * enforces it under a lock on the bank account row, as BankDepositService does
 * for cheques.
 *
 * The journal number, date, cheque and amount are snapshotted so the printed
 * reconciliation statement reads the same forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_bank_reconciliation_lines', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('bank_reconciliation_id')->constrained('fin_bank_reconciliations')->cascadeOnDelete();
            $table->foreignId('journal_line_id')->constrained('fin_journal_lines')->restrictOnDelete();

            // deposit (debit on the bank) | withdrawal (credit)
            $table->string('direction', 12);
            $table->string('status', 20);
            $table->date('cleared_date')->nullable();

            $table->string('journal_no', 30);
            $table->date('journal_date');
            $table->string('instrument_type', 20)->nullable();
            $table->string('instrument_no', 30)->nullable();
            $table->date('instrument_date')->nullable();
            $table->string('description', 255)->nullable();
            $table->decimal('amount', 15, 2);

            $table->string('return_journal_no', 30)->nullable();

            $table->timestamps();

            $table->unique(['bank_reconciliation_id', 'journal_line_id'], 'fin_brl_recon_line_unique');
            $table->index(['journal_line_id', 'status'], 'fin_brl_line_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_bank_reconciliation_lines');
    }
};
