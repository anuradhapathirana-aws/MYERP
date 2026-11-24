<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Which location a posted document belongs to, asked by the ledger.
 *
 * A journal's location is set by whoever builds it (InvoiceJournal takes the
 * DO's, CustomerReturnJournal the return's). Journals posted before that was
 * done carry none, so a location-filtered report misses them. Finance's
 * backfill command asks the owning module through this port, by source type
 * and id, and never reads that module's tables itself.
 *
 * With the owning module absent the null implementation knows nothing, and
 * those journals are simply left as they are.
 */
interface DocumentLocationSource
{
    /**
     * The source types (fin_journal_entries.source_type) this can answer for.
     *
     * @return list<string>
     */
    public function sourceTypes(): array;

    /**
     * Location id per document id. A document with no location is left out.
     *
     * @param  list<int>      $ids
     * @return array<int, int> document id => location id
     */
    public function locationsFor(string $sourceType, array $ids): array;
}
