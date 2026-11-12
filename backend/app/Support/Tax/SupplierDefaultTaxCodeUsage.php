<?php

declare(strict_types=1);

namespace App\Support\Tax;

use App\Contracts\TaxCodeUsage;
use App\Models\SupplierMaster;

/** Suppliers that start their GRN lines with the code. */
final class SupplierDefaultTaxCodeUsage implements TaxCodeUsage
{
    public function usedBy(int $taxCodeId): ?string
    {
        $count = SupplierMaster::where('default_tax_code_id', $taxCodeId)->count();

        return $count > 0 ? "the default of {$count} " . ($count === 1 ? 'supplier' : 'suppliers') : null;
    }
}
