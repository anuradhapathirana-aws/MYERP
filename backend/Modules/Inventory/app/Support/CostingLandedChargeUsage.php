<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

use App\Contracts\LandedChargeUsage;
use Modules\Inventory\Models\CostingBillCharge;

/** Bill lines pulled into a live (not deleted) costing — the bill must stay. */
final class CostingLandedChargeUsage implements LandedChargeUsage
{
    public function usedBy(array $sourceIds): ?string
    {
        if ($sourceIds === []) {
            return null;
        }

        $documents = CostingBillCharge::query()
            ->where('source_type', CostingBillCharge::SOURCE_BILL_LINE)
            ->whereIn('source_id', array_map('intval', $sourceIds))
            ->whereHas('costing')
            ->with('costing:id,document_no')
            ->get()
            ->map(fn (CostingBillCharge $c) => $c->costing?->document_no)
            ->filter()
            ->unique()
            ->values();

        return $documents->isEmpty() ? null : 'Costing ' . $documents->join(', ');
    }
}
