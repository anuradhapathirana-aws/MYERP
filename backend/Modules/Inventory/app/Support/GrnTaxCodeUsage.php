<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

use App\Contracts\TaxCodeUsage;
use Modules\Inventory\Models\GoodsReceivedNoteItem;

/** GRN lines priced with the code — a posted GRN must keep its code forever. */
final class GrnTaxCodeUsage implements TaxCodeUsage
{
    public function usedBy(int $taxCodeId): ?string
    {
        $count = GoodsReceivedNoteItem::where('tax_code_id', $taxCodeId)->count();

        return $count > 0 ? "the tax code of {$count} GRN " . ($count === 1 ? 'line' : 'lines') : null;
    }
}
