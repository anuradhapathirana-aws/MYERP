<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A bill payment's cheque can come back from the bank (stopped, refused).
 * Bank Reconciliation then posts its bounce entry and the bills are owed
 * again; the payment records when, where, and the entry that did it.
 *
 * The bank line of a bill payment now names the payment (source
 * bill_payment / id), as receipt and payment settlements already do, so the
 * reconciliation can return it. Existing lines -- and their reversal mirrors
 * -- are tagged the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_bill_payments', function (Blueprint $table): void {
            $table->date('returned_date')->nullable()->after('reversal_journal_no');
            $table->string('return_reference', 50)->nullable()->after('returned_date');
            $table->string('return_journal_no', 30)->nullable()->after('return_reference');
            $table->timestamp('returned_at')->nullable()->after('return_journal_no');
            $table->unsignedBigInteger('returned_by')->nullable()->after('returned_at');
        });

        $payments = DB::table('fin_journal_entries as e')
            ->join('fin_bill_payments as p', 'p.id', '=', 'e.source_id')
            ->where('e.source_type', 'bill_payment')
            ->get(['e.id as entry_id', 'p.id as payment_id', 'p.pay_from_account_id']);

        foreach ($payments as $row) {
            DB::table('fin_journal_lines')
                ->where('journal_entry_id', $row->entry_id)
                ->where('ledger_account_id', $row->pay_from_account_id)
                ->where('credit', '>', 0)
                ->whereNull('source_type')
                ->update(['source_type' => 'bill_payment', 'source_id' => $row->payment_id]);

            // The void's mirror line keeps naming the same payment.
            $reversals = DB::table('fin_journal_entries')->where('reverses_journal_id', $row->entry_id)->pluck('id');

            if ($reversals->isNotEmpty()) {
                DB::table('fin_journal_lines')
                    ->whereIn('journal_entry_id', $reversals)
                    ->where('ledger_account_id', $row->pay_from_account_id)
                    ->where('debit', '>', 0)
                    ->whereNull('source_type')
                    ->update(['source_type' => 'bill_payment', 'source_id' => $row->payment_id]);
            }
        }
    }

    public function down(): void
    {
        DB::table('fin_journal_lines')->where('source_type', 'bill_payment')->update(['source_type' => null, 'source_id' => null]);

        Schema::table('fin_bill_payments', function (Blueprint $table): void {
            $table->dropColumn(['returned_date', 'return_reference', 'return_journal_no', 'returned_at', 'returned_by']);
        });
    }
};
