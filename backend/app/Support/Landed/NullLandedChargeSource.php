<?php

declare(strict_types=1);

namespace App\Support\Landed;

use App\Contracts\LandedChargeSource;

/**
 * What answers when no Finance module is active: there are no bills, so
 * nothing to pull. Costing keeps working with typed amounts, exactly as on an
 * Inventory-only installation.
 */
final class NullLandedChargeSource implements LandedChargeSource
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function chargesForGrns(array $grnIds): array
    {
        return [];
    }

    public function chargesByIds(array $sourceIds): array
    {
        return [];
    }
}
