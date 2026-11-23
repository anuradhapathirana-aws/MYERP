<?php

declare(strict_types=1);

namespace App\Support\Stock;

use App\Contracts\StockValuationSource;

/**
 * What answers when no Inventory module is active: there is no stock ledger to
 * value, so the Profit & Loss asks the user for opening and closing stock
 * (from a physical count) instead.
 */
final class NullStockValuationSource implements StockValuationSource
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function valuesAsAt(
        array $dates,
        ?int $companyId = null,
        ?int $locationId = null,
        ?array $allowedCompanyIds = null,
    ): array {
        return [];
    }

    public function basis(): string
    {
        return 'Inventory module not active: enter stock values from your stock count.';
    }
}
