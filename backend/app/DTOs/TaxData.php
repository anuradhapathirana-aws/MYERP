<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\TaxType;
use App\Http\Requests\TaxRequest;

final class TaxData
{
    public function __construct(
        public readonly string  $code,
        public readonly string  $name,
        public readonly TaxType $tax_type,
        public readonly float   $rate,
        public readonly string  $effective_from,
        public readonly bool    $is_active,
    ) {}

    public static function fromRequest(TaxRequest $request): self
    {
        return new self(
            code:           strtoupper(trim((string) $request->input('code'))),
            name:           trim((string) $request->input('name')),
            tax_type:       TaxType::from((string) $request->input('tax_type')),
            rate:           round((float) $request->input('rate'), 2),
            effective_from: (string) $request->date('effective_from')?->format('Y-m-d'),
            is_active:      (bool) $request->input('is_active', true),
        );
    }
}
