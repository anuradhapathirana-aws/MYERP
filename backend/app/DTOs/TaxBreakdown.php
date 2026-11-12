<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * The result of applying a tax code to an amount: the amount before tax and
 * each tax on it. base + taxTotal() is always exactly total(), to the cent.
 */
final class TaxBreakdown
{
    /** @param list<TaxBreakdownLine> $lines */
    public function __construct(
        public readonly float $base,
        public readonly array $lines,
    ) {}

    public static function none(float $amount): self
    {
        return new self(round($amount, 2), []);
    }

    public function taxTotal(): float
    {
        return round(array_sum(array_map(fn (TaxBreakdownLine $l) => $l->amount, $this->lines)), 2);
    }

    public function total(): float
    {
        return round($this->base + $this->taxTotal(), 2);
    }

    /** Claimable as input tax — posted to Input Tax Recoverable. */
    public function recoverable(): float
    {
        return round(array_sum(array_map(
            fn (TaxBreakdownLine $l) => $l->isRecoverable ? $l->amount : 0.0,
            $this->lines,
        )), 2);
    }

    /** Not claimable — part of what the goods cost. */
    public function nonRecoverable(): float
    {
        return round($this->taxTotal() - $this->recoverable(), 2);
    }

    /** Total tax as a % of the base, for display and old % reports. */
    public function effectiveRate(): float
    {
        return $this->base > 0 ? round($this->taxTotal() / $this->base * 100, 4) : 0.0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'base'            => $this->base,
            'tax_total'       => $this->taxTotal(),
            'recoverable'     => $this->recoverable(),
            'non_recoverable' => $this->nonRecoverable(),
            'total'           => $this->total(),
            'lines'           => array_map(fn (TaxBreakdownLine $l) => $l->toArray(), $this->lines),
        ];
    }
}
