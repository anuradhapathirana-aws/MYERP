<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * What a module tells the core about money precision.
 *
 * Core cannot know every module's tables. Each module that stores money tags
 * its own implementation with TAG in its service provider; AmountPrecision
 * asks all of them. A disabled or absent module simply registers nothing.
 *
 *   usedBy()        why the Amount decimals setting must stay locked
 *                   ("3 GRNs"), or null when this module holds no documents.
 *   moneyColumns()  the columns holding AMOUNTS (totals, line amounts, tax,
 *                   payments), for the read-only precision audit. Unit prices,
 *                   unit costs, rates and quantities are deliberately NOT
 *                   listed: they keep their own, higher precision.
 */
interface AmountPrecisionUsage
{
    public const TAG = 'amount_precision_usages';

    public function usedBy(): ?string;

    /** @return array<string, list<string>> table => amount columns */
    public function moneyColumns(): array;
}
