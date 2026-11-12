<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * One supplier-bill line that adds to the cost of a shipment: what it was, which
 * GRN it belongs to, and how much of it is cost.
 *
 * $amount is the COST part only — the line before tax plus any tax that cannot
 * be claimed. Claimable VAT is never in it, so it never inflates a product's
 * cost or selling price.
 */
final class LandedCharge
{
    public function __construct(
        public readonly int     $sourceId,
        public readonly int     $billId,
        public readonly string  $billNo,
        public readonly ?string $billDate,
        public readonly ?string $partyName,
        public readonly string  $chargeName,
        public readonly int     $grnId,
        public readonly float   $amount,
        public readonly ?int    $fobExpenseTypeId,
        public readonly ?int    $cifExpenseTypeId,
    ) {}

    /** The Costing row this charge fills on a FOB or CIF costing, or null when it has none. */
    public function expenseTypeFor(string $costingType): ?int
    {
        return $costingType === 'cif' ? $this->cifExpenseTypeId : $this->fobExpenseTypeId;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source_id'           => $this->sourceId,
            'bill_id'             => $this->billId,
            'bill_no'             => $this->billNo,
            'bill_date'           => $this->billDate,
            'party_name'          => $this->partyName,
            'charge_name'         => $this->chargeName,
            'grn_id'              => $this->grnId,
            'amount'              => $this->amount,
            'fob_expense_type_id' => $this->fobExpenseTypeId,
            'cif_expense_type_id' => $this->cifExpenseTypeId,
        ];
    }
}
