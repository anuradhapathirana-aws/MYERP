<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Tells a bill whether any of its lines were pulled into a costing, so a bill
 * whose cost already sits in a selling price cannot be voided from under it.
 *
 * Same shape as TaxCodeUsage: each module that keeps landed charges tags its
 * own implementation with TAG; a disabled or absent module registers nothing.
 */
interface LandedChargeUsage
{
    public const TAG = 'landed_charge_usages';

    /**
     * Where the lines are used ("Costing CST-2026-0004"), or null when nowhere.
     *
     * @param list<int> $sourceIds bill line ids
     */
    public function usedBy(array $sourceIds): ?string;
}
