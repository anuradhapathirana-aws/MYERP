<?php

declare(strict_types=1);

namespace Modules\Finance\Enums;

/**
 * Where a bill line's money goes. Set by the line's Charge Type, so the person
 * entering a bill picks WHAT was charged and the books follow.
 *
 *   landed_cost      part of what the goods cost: posted to its account and
 *                    pulled into the shipment's Costing
 *   recoverable_tax  a tax we claim back (Import VAT on a CusDec): posted to
 *                    Input Tax Recoverable. While VAT is off for us it cannot
 *                    be claimed, so it becomes landed cost instead
 *   expense          a period cost (demurrage, bank charges): posted to its
 *                    account, never into a product's cost
 */
enum ChargeTreatment: string
{
    case LandedCost     = 'landed_cost';
    case RecoverableTax = 'recoverable_tax';
    case Expense        = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::LandedCost     => 'Landed cost',
            self::RecoverableTax => 'Claimable tax',
            self::Expense        => 'Expense',
        };
    }

    /** Can the line belong to a shipment (GRN) and so reach its Costing? */
    public function linksToGrn(): bool
    {
        return $this !== self::Expense;
    }

    /** @return list<array{value:string,label:string}> */
    public static function options(): array
    {
        return array_map(fn (self $t): array => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }
}
