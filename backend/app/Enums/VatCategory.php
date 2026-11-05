<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a line is reported on the VAT return. Several are 0 % yet reported
 * differently, which is why a tax code carries a category and not just a rate.
 */
enum VatCategory: string
{
    case Standard    = 'standard';
    case ZeroRated   = 'zero_rated';
    case Exempt      = 'exempt';
    case OutOfScope  = 'out_of_scope';
    case Import      = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Standard   => 'Standard rated',
            self::ZeroRated  => 'Zero rated',
            self::Exempt     => 'Exempt',
            self::OutOfScope => 'No Tax Invoice / out of scope',
            self::Import     => 'Import',
        };
    }

    /** Only a standard-rated code carries taxes; every other category is 0 % by definition. */
    public function carriesTax(): bool
    {
        return $this === self::Standard;
    }
}
