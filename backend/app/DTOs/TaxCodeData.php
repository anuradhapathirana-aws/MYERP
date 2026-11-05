<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\VatCategory;
use App\Http\Requests\TaxCodeRequest;

final class TaxCodeData
{
    /**
     * @param list<array{tax_code_ref: string, is_compound: bool, is_recoverable: bool}> $components in order
     */
    public function __construct(
        public readonly string      $code,
        public readonly string      $name,
        public readonly VatCategory $vat_category,
        public readonly bool        $is_active,
        public readonly int         $sort_order,
        public readonly array       $components,
    ) {}

    public static function fromRequest(TaxCodeRequest $request): self
    {
        $category = VatCategory::from((string) $request->input('vat_category'));

        return new self(
            code:         strtoupper(trim((string) $request->input('code'))),
            name:         trim((string) $request->input('name')),
            vat_category: $category,
            is_active:    (bool) $request->input('is_active', true),
            sort_order:   (int) $request->input('sort_order', 0),
            components:   $category->carriesTax()
                ? array_values(array_map(fn (array $c) => [
                    'tax_code_ref'   => strtoupper(trim((string) $c['tax_code_ref'])),
                    'is_compound'    => (bool) ($c['is_compound'] ?? false),
                    'is_recoverable' => (bool) ($c['is_recoverable'] ?? false),
                ], (array) $request->input('components', [])))
                : [],
        );
    }
}
