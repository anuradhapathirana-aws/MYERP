<?php

declare(strict_types=1);

namespace Modules\Finance\Console\Commands;

use App\Contracts\DocumentLocationSource;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills the missing location on journals posted from invoices and customer
 * returns before their journal builders set one.
 *
 * Without it a location-filtered P&L, Trial Balance or Ledger shows that
 * location's purchases and stock but none of its sales. The location comes
 * from the module that owns the document (DocumentLocationSource): an
 * invoice's DO, a return's own location. Reversals of those journals get the
 * same location so they keep netting to nothing inside the location.
 *
 * ── What it changes, and what it never does ─────────────────────────────────
 * Only BLANK location_id values, on the journal header and its lines. A
 * location already set is never overwritten, and no amount, account, date or
 * status changes, so every unfiltered report is identical before and after.
 * A location is a reporting tag, so the period lock does not apply.
 *
 * Dry-run by default: --apply writes, in one transaction, with one audit entry
 * per journal changed.
 */
class BackfillJournalLocations extends Command
{
    protected $signature = 'finance:backfill-journal-locations
        {--apply : Write the changes (without it nothing is changed)}';

    protected $description = 'Fill the missing location on invoice and customer-return journals (dry-run unless --apply)';

    private const CHUNK = 500;

    public function handle(DocumentLocationSource $documents, AuditLogger $audit): int
    {
        $apply   = (bool) $this->option('apply');
        $targets = $this->targets($documents);

        if ($targets === []) {
            $this->info('Nothing to fill: every invoice and customer-return journal already has its location.');

            return self::SUCCESS;
        }

        $lines = $this->blankLineCounts(array_keys($targets));

        $this->table(
            ['Journal', 'Date', 'Status', 'Source', 'Location id', 'Header blank', 'Lines to fill'],
            array_map(fn (array $t): array => [
                $t['journal_no'],
                substr((string) $t['journal_date'], 0, 10),
                $t['status'],
                $t['source'],
                $t['location_id'],
                $t['header_blank'] ? 'yes' : 'no',
                $lines[$t['id']] ?? 0,
            ], array_values($targets)),
        );

        $journalCount = count($targets);
        $lineCount    = array_sum($lines);

        if (! $apply) {
            $this->warn("Dry run: {$journalCount} journal(s), {$lineCount} line(s) would get a location. Nothing was changed.");
            $this->line('Run again with --apply to write. Amounts, accounts, dates and statuses are never touched.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($targets, $lines, $audit): void {
            foreach ($targets as $id => $t) {
                if ($t['header_blank']) {
                    DB::table('fin_journal_entries')->where('id', $id)->whereNull('location_id')
                        ->update(['location_id' => $t['location_id']]);
                }

                DB::table('fin_journal_lines')->where('journal_entry_id', $id)->whereNull('location_id')
                    ->update(['location_id' => $t['location_id']]);

                $audit->record(
                    'journal_entry',
                    $id,
                    'location_backfilled',
                    ['location_id' => null],
                    ['location_id' => $t['location_id'], 'lines' => $lines[$id] ?? 0, 'source' => $t['source']],
                );
            }
        });

        $this->info("Done: {$journalCount} journal(s), {$lineCount} line(s) now carry their location.");

        return self::SUCCESS;
    }

    /**
     * Journals with a blank location on the header or on any line, with the
     * location their document gives. Reversals inherit their original's.
     *
     * @return array<int, array{id: int, journal_no: string, journal_date: string, status: string, source: string, location_id: int, header_blank: bool}>
     */
    private function targets(DocumentLocationSource $documents): array
    {
        $targets = [];

        foreach ($documents->sourceTypes() as $type) {
            $this->journalsWithBlanks()
                ->where('je.source_type', $type)
                ->whereNotNull('je.source_id')
                ->orderBy('je.id')
                ->chunk(self::CHUNK, function ($rows) use ($documents, $type, &$targets): void {
                    $locations = $documents->locationsFor($type, $rows->pluck('source_id')->map(fn ($v): int => (int) $v)->all());

                    foreach ($rows as $r) {
                        $location = $locations[(int) $r->source_id] ?? null;

                        if ($location !== null) {
                            $targets[(int) $r->id] = $this->target($r, "{$type} #{$r->source_id}", $location);
                        }
                    }
                });
        }

        foreach (array_chunk(array_keys($targets), self::CHUNK) as $originals) {
            foreach ($this->journalsWithBlanks()->whereIn('je.reverses_journal_id', $originals)->get() as $r) {
                $original = $targets[(int) $r->reverses_journal_id];
                $targets[(int) $r->id] = $this->target($r, "reversal of {$original['journal_no']}", $original['location_id']);
            }
        }

        ksort($targets);

        return $targets;
    }

    private function journalsWithBlanks(): \Illuminate\Database\Query\Builder
    {
        return DB::table('fin_journal_entries as je')
            ->whereNull('je.deleted_at')
            ->where(fn ($q) => $q
                ->whereNull('je.location_id')
                ->orWhereExists(fn ($l) => $l->selectRaw('1')->from('fin_journal_lines as jl')
                    ->whereColumn('jl.journal_entry_id', 'je.id')
                    ->whereNull('jl.location_id')))
            ->select(['je.id', 'je.journal_no', 'je.journal_date', 'je.status', 'je.source_id', 'je.location_id', 'je.reverses_journal_id']);
    }

    /** @return array{id: int, journal_no: string, journal_date: string, status: string, source: string, location_id: int, header_blank: bool} */
    private function target(object $r, string $source, int $location): array
    {
        return [
            'id'           => (int) $r->id,
            'journal_no'   => (string) $r->journal_no,
            'journal_date' => (string) $r->journal_date,
            'status'       => (string) $r->status,
            'source'       => $source,
            'location_id'  => $location,
            'header_blank' => $r->location_id === null,
        ];
    }

    /**
     * @param  list<int> $journalIds
     * @return array<int, int> journal id => blank lines
     */
    private function blankLineCounts(array $journalIds): array
    {
        $counts = [];

        foreach (array_chunk($journalIds, self::CHUNK) as $chunk) {
            DB::table('fin_journal_lines')
                ->whereIn('journal_entry_id', $chunk)
                ->whereNull('location_id')
                ->groupBy('journal_entry_id')
                ->selectRaw('journal_entry_id, COUNT(*) AS n')
                ->get()
                ->each(function ($r) use (&$counts): void {
                    $counts[(int) $r->journal_entry_id] = (int) $r->n;
                });
        }

        return $counts;
    }
}
