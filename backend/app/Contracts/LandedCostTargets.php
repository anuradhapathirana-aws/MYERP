<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Where landed costs go: the GRNs a bill line can be linked to, and the Costing
 * rows (FOB / CIF expense types) a charge type fills.
 *
 * Finance's Bill Entry and Charge Types read them through this port and never
 * touch an inv_ table; Inventory answers. With Inventory absent or switched off
 * the null implementation answers "nothing", and bills work as plain expense
 * bills.
 */
interface LandedCostTargets
{
    /** Whether GRNs can be linked at all (Inventory installed and switched on). */
    public function isAvailable(): bool;

    /**
     * Confirmed GRNs for the bill line's picker, newest first.
     *
     * @return list<array{id:int, grn_no:string, grn_date:?string, supplier_name:?string, total_amount:float}>
     */
    public function searchGrns(?string $term, int $limit = 20): array;

    /**
     * Confirmed GRNs by id, keyed by id. A GRN that does not exist or is not
     * confirmed is missing from the result.
     *
     * @param  list<int> $ids
     * @return array<int, array{id:int, grn_no:string, grn_date:?string, supplier_name:?string, total_amount:float}>
     */
    public function grnsByIds(array $ids): array;

    /**
     * The Costing expense rows a charge type can fill.
     *
     * @return list<array{id:int, name:string, costing_type:string}>
     */
    public function costingRows(): array;
}
