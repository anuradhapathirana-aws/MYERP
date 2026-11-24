<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

use App\Contracts\DocumentLocationSource;
use App\Support\Journal\PostingRole;
use Illuminate\Support\Facades\DB;

/**
 * Inventory's answer to "which location does this posted document belong to",
 * using the same rule as the journal builders:
 *
 *   invoice          the DO's location (InvoiceJournal::locationOf); a
 *                    direct-SO invoice has no DO and no location
 *   customer_return  the return's own location
 *
 * One query per source type, whatever the number of ids. Soft-deleted
 * documents still answer: their journals exist and still count.
 */
final class InventoryDocumentLocations implements DocumentLocationSource
{
    public function sourceTypes(): array
    {
        return [PostingRole::SOURCE_INVOICE, PostingRole::SOURCE_CUSTOMER_RETURN];
    }

    public function locationsFor(string $sourceType, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        $query = match ($sourceType) {
            PostingRole::SOURCE_INVOICE => DB::table('inv_invoices as i')
                ->join('inv_delivery_orders as d', 'd.id', '=', 'i.do_id')
                ->whereIn('i.id', $ids)
                ->whereNotNull('d.location_id')
                ->select(['i.id', 'd.location_id']),
            PostingRole::SOURCE_CUSTOMER_RETURN => DB::table('inv_customer_returns as r')
                ->whereIn('r.id', $ids)
                ->whereNotNull('r.location_id')
                ->select(['r.id', 'r.location_id']),
            default => null,
        };

        if ($query === null) {
            return [];
        }

        return $query->pluck('location_id', 'id')
            ->mapWithKeys(fn ($loc, $id): array => [(int) $id => (int) $loc])
            ->all();
    }
}
