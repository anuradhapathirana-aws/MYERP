<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * What the stock on hand was worth at the end of a given day.
 *
 * Finance keeps stock on the periodic method: a GRN debits Purchases, and
 * nothing in the ledger records what is left. The Profit & Loss still needs an
 * opening and a closing stock figure, and only Inventory knows them. Finance
 * asks through this port and never touches an inv_ table; Inventory answers.
 * With Inventory absent or switched off the null implementation answers
 * "not available" and the user types the figures in instead.
 *
 * When Finance moves to perpetual stock, this same valuation becomes the cost
 * source for Cost of Sales postings.
 */
interface StockValuationSource
{
    /** Whether a valuation can be produced (Inventory installed and switched on). */
    public function isAvailable(): bool;

    /**
     * Stock value at the END of each date (that day's movements included).
     *
     * One answer per requested date, keyed by the date string exactly as
     * given ('Y-m-d'). Empty when not available.
     *
     * @param  list<string>   $dates             'Y-m-d'
     * @param  int|null       $companyId         only this company's locations
     * @param  int|null       $locationId        only this location
     * @param  list<int>|null $allowedCompanyIds company access; null = unrestricted
     * @return array<string, float>
     */
    public function valuesAsAt(
        array $dates,
        ?int $companyId = null,
        ?int $locationId = null,
        ?array $allowedCompanyIds = null,
    ): array;

    /**
     * Deliveries dated inside the period whose goods left stock but which had
     * no invoice dated on or before the period end.
     *
     * Their cost is already inside the stock movement (opening − closing), but
     * their sale is not in the ledger yet, so the Profit & Loss warns about
     * them. Cost is valued like the stock figures: quantity × average cost on
     * the delivery date. Empty when not available.
     *
     * @param  string         $from              'Y-m-d', first day of the period
     * @param  string         $to                'Y-m-d', last day of the period
     * @param  int|null       $companyId         only this company's locations
     * @param  int|null       $locationId        only this location
     * @param  list<int>|null $allowedCompanyIds company access; null = unrestricted
     * @return list<array{document_no: string, date: string, cost: float}>
     */
    public function uninvoicedDeliveries(
        string $from,
        string $to,
        ?int $companyId = null,
        ?int $locationId = null,
        ?array $allowedCompanyIds = null,
    ): array;

    /** One line for the report saying how the figure was worked out. */
    public function basis(): string;
}
