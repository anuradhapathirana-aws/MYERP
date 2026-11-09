<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\LandedCharge;

/**
 * Where landed costs come from: the confirmed supplier-bill lines (freight,
 * duty, clearing...) that were linked to a GRN.
 *
 * Inventory's Costing reads them through this port and never touches a fin_
 * table; Finance answers. With Finance absent or switched off the null
 * implementation answers "nothing", and Costing falls back to typed amounts.
 */
interface LandedChargeSource
{
    /** Whether bills can be pulled at all (Finance installed and switched on). */
    public function isAvailable(): bool;

    /**
     * Every confirmed landed-cost line linked to any of these GRNs.
     *
     * @param  list<int> $grnIds
     * @return list<LandedCharge>
     */
    public function chargesForGrns(array $grnIds): array;

    /**
     * The given lines, if they are still confirmed landed-cost lines. A voided
     * bill's line is simply missing from the result.
     *
     * @param  list<int> $sourceIds
     * @return list<LandedCharge>
     */
    public function chargesByIds(array $sourceIds): array;
}
