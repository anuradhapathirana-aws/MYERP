<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Tells the tax-code master whether a code is still referenced somewhere, so a
 * code on a posted document can never be deleted from under it.
 *
 * Core cannot know every module's tables. Each module that stores a tax code
 * tags its own implementation with TAG in its service provider; the master asks
 * all of them. A disabled or absent module simply registers nothing.
 */
interface TaxCodeUsage
{
    public const TAG = 'tax_code_usages';

    /** A short description of where the code is used ("3 GRN lines"), or null when it is not. */
    public function usedBy(int $taxCodeId): ?string;
}
