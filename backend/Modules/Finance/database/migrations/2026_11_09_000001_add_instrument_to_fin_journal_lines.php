<?php

declare(strict_types=1);

use App\Enums\PaymentModeType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How the money moved, on the cash or bank line itself.
 *
 * Until now a cheque number lived only on the source document (a settlement
 * row, a bill payment) and inside the journal line's description text. Bank
 * Reconciliation has to list, search and match bank lines by cheque number and
 * date, so they become real columns:
 *
 *   instrument_type  PaymentModeType value: cash | cheque | bank_transfer | card
 *   instrument_no    cheque number / transfer reference
 *   instrument_date  cheque date
 *
 * The money line of a Supplier Payment or Customer Receipt also gets
 * source_type/source_id naming its SETTLEMENT row (one cheque), so a single
 * cheque can later be returned without guessing which line it was.
 *
 * ── Backfill ─────────────────────────────────────────────────────────────────
 * Existing posted lines are filled from their source documents. Only the new
 * columns, and source on money lines that had none, are written: amounts,
 * accounts and dates of a posted journal are never touched. Settlement rows of
 * a confirmed document are stable (only drafts rebuild them), and the journal
 * built them in settlement order, so the Nth money line is the Nth settlement
 * on that account for that amount. Reversals copy from the line they mirror.
 *
 * Bank Deposits posted before per-cheque deposit lines keep their single slip
 * line: a posted journal is immutable, so those cheques reconcile as one slip.
 *
 * Inventory tables are read through hasTable, so a Finance-only installation
 * migrates cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_journal_lines', function (Blueprint $table): void {
            $table->string('instrument_type', 20)->nullable()->after('source_id');
            $table->string('instrument_no', 30)->nullable()->after('instrument_type');
            $table->date('instrument_date')->nullable()->after('instrument_no');

            $table->index(['ledger_account_id', 'instrument_no'], 'fin_jl_acct_instrument_idx');
        });

        $modeTypes = $this->modeTypes();

        $this->backfillSettlements(
            'supplier_payment',
            'inv_supplier_payment_settlements',
            'payment_id',
            'supplier_payment_settlement',
            'credit',
            $modeTypes,
        );

        $this->backfillSettlements(
            'customer_receipt',
            'inv_customer_receipt_settlements',
            'receipt_id',
            'customer_receipt_settlement',
            'debit',
            $modeTypes,
        );

        $this->backfillBillPayments($modeTypes);
        $this->backfillReversals();
    }

    public function down(): void
    {
        Schema::table('fin_journal_lines', function (Blueprint $table): void {
            $table->dropIndex('fin_jl_acct_instrument_idx');
            $table->dropColumn(['instrument_type', 'instrument_no', 'instrument_date']);
        });

        // Source tags written by up() on money lines are removed again.
        DB::table('fin_journal_lines')
            ->whereIn('source_type', ['supplier_payment_settlement', 'customer_receipt_settlement'])
            ->update(['source_type' => null, 'source_id' => null]);
    }

    /** @return array<int, string> payment mode id => PaymentModeType value */
    private function modeTypes(): array
    {
        if (! Schema::hasTable('inv_payment_modes')) {
            return [];
        }

        $hasType = Schema::hasColumn('inv_payment_modes', 'type');

        return DB::table('inv_payment_modes')
            ->get($hasType ? ['id', 'code', 'type'] : ['id', 'code'])
            ->mapWithKeys(fn (object $m): array => [
                (int) $m->id => ($hasType && $m->type) ? (string) $m->type : PaymentModeType::fromLegacyCode($m->code)->value,
            ])
            ->all();
    }

    /**
     * @param  'debit'|'credit'      $side      the side the money line sits on
     * @param  array<int, string>    $modeTypes
     */
    private function backfillSettlements(
        string $journalSource,
        string $settlementTable,
        string $parentKey,
        string $lineSource,
        string $side,
        array $modeTypes,
    ): void {
        if (! Schema::hasTable($settlementTable)
            || ! Schema::hasColumn($settlementTable, 'ledger_account_id')) {
            return;
        }

        DB::table('fin_journal_entries')
            ->where('source_type', $journalSource)
            ->orderBy('id')
            ->select(['id', 'source_id'])
            ->chunk(200, function ($entries) use ($settlementTable, $parentKey, $lineSource, $side, $modeTypes): void {
                $settlements = DB::table($settlementTable)
                    ->whereIn($parentKey, $entries->pluck('source_id')->all())
                    ->where('amount', '>', 0)
                    ->whereNotNull('ledger_account_id')
                    ->orderBy('id')
                    ->get()
                    ->groupBy($parentKey);

                $lines = DB::table('fin_journal_lines')
                    ->whereIn('journal_entry_id', $entries->pluck('id')->all())
                    ->where($side, '>', 0)
                    ->whereNull('party_type')
                    ->orderBy('line_no')
                    ->get(['id', 'journal_entry_id', 'ledger_account_id', $side . ' as amount'])
                    ->groupBy('journal_entry_id');

                foreach ($entries as $entry) {
                    $pool = collect($lines[$entry->id] ?? []);

                    foreach ($settlements[$entry->source_id] ?? [] as $settlement) {
                        $index = $pool->search(fn (object $l): bool =>
                            (int) $l->ledger_account_id === (int) $settlement->ledger_account_id
                            && abs((float) $l->amount - round((float) $settlement->amount, 2)) < 0.005);

                        if ($index === false) {
                            continue;
                        }

                        $line = $pool->pull($index);

                        DB::table('fin_journal_lines')->where('id', $line->id)->update([
                            'source_type'     => $lineSource,
                            'source_id'       => (int) $settlement->id,
                            'instrument_type' => $modeTypes[(int) $settlement->payment_mode_id] ?? null,
                            'instrument_no'   => mb_substr(trim((string) $settlement->reference_no), 0, 30) ?: null,
                            'instrument_date' => $settlement->instrument_date,
                        ]);
                    }
                }
            });
    }

    /** @param array<int, string> $modeTypes */
    private function backfillBillPayments(array $modeTypes): void
    {
        if (! Schema::hasTable('fin_bill_payments')) {
            return;
        }

        $payments = DB::table('fin_journal_entries as e')
            ->join('fin_bill_payments as p', 'p.id', '=', 'e.source_id')
            ->where('e.source_type', 'bill_payment')
            ->get(['e.id as entry_id', 'p.pay_from_account_id', 'p.payment_mode_id', 'p.payment_mode_code',
                'p.cheque_no', 'p.cheque_date', 'p.reference_no']);

        foreach ($payments as $p) {
            $type = $modeTypes[(int) $p->payment_mode_id] ?? PaymentModeType::fromLegacyCode($p->payment_mode_code)->value;

            DB::table('fin_journal_lines')
                ->where('journal_entry_id', $p->entry_id)
                ->where('ledger_account_id', $p->pay_from_account_id)
                ->where('credit', '>', 0)
                ->update([
                    'instrument_type' => $type,
                    // The column holds 30; a bill payment reference may be 100.
                    'instrument_no'   => mb_substr(trim((string) ($p->cheque_no ?? $p->reference_no)), 0, 30) ?: null,
                    'instrument_date' => $p->cheque_date,
                ]);
        }
    }

    /** A reversal mirrors its original line for line, in the same order. */
    private function backfillReversals(): void
    {
        $reversals = DB::table('fin_journal_entries')
            ->whereNotNull('reverses_journal_id')
            ->get(['id', 'reverses_journal_id']);

        foreach ($reversals as $reversal) {
            $original = DB::table('fin_journal_lines')
                ->where('journal_entry_id', $reversal->reverses_journal_id)
                ->orderBy('line_no')
                ->orderBy('id')
                ->get(['source_type', 'source_id', 'instrument_type', 'instrument_no', 'instrument_date'])
                ->values();

            $mirror = DB::table('fin_journal_lines')
                ->where('journal_entry_id', $reversal->id)
                ->orderBy('line_no')
                ->orderBy('id')
                ->get(['id'])
                ->values();

            foreach ($mirror as $i => $line) {
                $from = $original[$i] ?? null;

                if ($from === null || ($from->instrument_type === null && $from->instrument_no === null)) {
                    continue;
                }

                DB::table('fin_journal_lines')->where('id', $line->id)->update([
                    'source_type'     => $from->source_type,
                    'source_id'       => $from->source_id,
                    'instrument_type' => $from->instrument_type,
                    'instrument_no'   => $from->instrument_no,
                    'instrument_date' => $from->instrument_date,
                ]);
            }
        }
    }
};
