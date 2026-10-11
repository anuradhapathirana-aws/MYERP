<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Discard draft journals belonging to documents that were since voided.
 *
 * reverseDocument() used to look only for a POSTED journal. In Review mode a
 * confirm creates a DRAFT, so voiding the document left that draft in the
 * Journal Vouchers list with a live Post button -- someone could post a journal
 * for a receipt or payment that no longer exists. The service is fixed; this
 * clears the ones already created.
 *
 * ── Why this lives in backend/database/migrations/ ───────────────────────────
 * It has to read Inventory's documents AND Finance's journals, so it belongs to
 * neither module. Same placement and same reasoning as
 * 2026_10_11_000001_add_ledger_account_id_to_settlement_tables.php. Every table
 * is behind a hasTable() guard, so it is a no-op on an installation missing
 * either module rather than an error.
 *
 * Only DRAFTS are touched. A posted journal for a voided document already has
 * its reversal and must never be removed.
 */
return new class extends Migration
{
    /** Journal source_type => the table whose status says whether it was voided. */
    private const SOURCES = [
        'supplier_payment' => 'inv_supplier_payments',
        'customer_receipt' => 'inv_customer_receipts',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('fin_journal_entries') || ! Schema::hasTable('fin_journal_lines')) {
            return;
        }

        foreach (self::SOURCES as $sourceType => $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $voidedIds = DB::table($table)->where('status', 'voided')->pluck('id');

            if ($voidedIds->isEmpty()) {
                continue;
            }

            // Chunked because whereIn on a large id list is the one place this
            // could outgrow a query. Realistically a handful of rows.
            foreach ($voidedIds->chunk(500) as $chunk) {
                $orphans = DB::table('fin_journal_entries')
                    ->where('source_type', $sourceType)
                    ->whereIn('source_id', $chunk->all())
                    ->where('status', 'draft')
                    ->whereNull('deleted_at')
                    ->pluck('id');

                if ($orphans->isEmpty()) {
                    continue;
                }

                DB::table('fin_journal_lines')->whereIn('journal_entry_id', $orphans)->delete();
                DB::table('fin_journal_entries')->whereIn('id', $orphans)->delete();
            }
        }
    }

    /**
     * Irreversible. These drafts describe transactions that never happened;
     * recreating them would restore the defect.
     */
    public function down(): void
    {
        // Intentionally empty.
    }
};
