<?php

declare(strict_types=1);

namespace App\DTOs;

/** One tax worked out on one document line. */
final class TaxBreakdownLine
{
    public function __construct(
        public readonly string $taxCodeRef,
        public readonly float  $rate,
        public readonly float  $base,
        public readonly float  $amount,
        public readonly bool   $isRecoverable,
    ) {}

    /** @return array{tax_code_ref: string, rate: float, base: float, amount: float, is_recoverable: bool} */
    public function toArray(): array
    {
        return [
            'tax_code_ref'   => $this->taxCodeRef,
            'rate'           => $this->rate,
            'base'           => $this->base,
            'amount'         => $this->amount,
            'is_recoverable' => $this->isRecoverable,
        ];
    }
}
