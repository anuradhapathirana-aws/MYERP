<?php

declare(strict_types=1);

namespace App\Support\Landed;

use App\Contracts\LandedCostTargets;

/**
 * What answers when no Inventory module is active: there are no GRNs or
 * costings, so a bill line cannot be linked and every bill is a plain expense
 * bill.
 */
final class NullLandedCostTargets implements LandedCostTargets
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function searchGrns(?string $term, int $limit = 20): array
    {
        return [];
    }

    public function grnsByIds(array $ids): array
    {
        return [];
    }

    public function costingRows(): array
    {
        return [];
    }
}
