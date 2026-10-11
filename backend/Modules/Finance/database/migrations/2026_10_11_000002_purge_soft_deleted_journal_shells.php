<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove the empty shells left by the old draft-delete.
 *
 * JournalEntryService::delete() used to hard-delete a draft's lines but only
 * SOFT-delete its header. The result was a row that is invisible everywhere,
 * holds no lines, and can never be restored into anything meaningful -- while
 * still occupying the unique (source_type, source_id) slot, which silently
 * prevented its own source document from ever posting again.
 *
 * It also caused the reported 404: route model binding excludes trashed rows,
 * so clicking Delete a second time on a row the list had not yet refreshed
 * produced "No query results for model [JournalEntry] 6".
 *
 * Deleting these is safe and loses nothing. Every one of them was a DRAFT --
 * the old guard refused to delete anything else -- so none was ever part of the
 * books, and their lines are already gone.
 *
 * Journal numbers are NOT reused afterwards: nextJournalNo() reads through
 * DB::table(), which never counted these rows in the first place.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fin_journal_entries')) {
            return;
        }

        $shells = DB::table('fin_journal_entries')
            ->whereNotNull('deleted_at')
            ->pluck('id');

        if ($shells->isEmpty()) {
            return;
        }

        // Lines first. There should be none -- the old code deleted them -- but
        // a shell that somehow kept its lines would otherwise leave rows
        // pointing at a header that no longer exists.
        DB::table('fin_journal_lines')->whereIn('journal_entry_id', $shells)->delete();
        DB::table('fin_journal_entries')->whereIn('id', $shells)->delete();
    }

    /**
     * Irreversible, and nothing is lost by that.
     *
     * These rows were discarded drafts with no lines. Recreating empty headers
     * on a rollback would restore the exact defect this migration removes.
     */
    public function down(): void
    {
        // Intentionally empty.
    }
};
