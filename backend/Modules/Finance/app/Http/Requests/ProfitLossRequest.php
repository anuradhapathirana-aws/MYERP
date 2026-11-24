<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Requests;

/**
 * Profit & Loss: the shared ledger filters (period, company, location, and
 * company access), plus:
 *
 *   - both dates REQUIRED: a P&L is always "for a period";
 *   - an optional comparative period (compare=1). Without compare_from /
 *     compare_to the service picks the period just before;
 *   - optional manual stock figures. Opening and closing stock come from
 *     Inventory when it is on; any figure given here replaces the automatic
 *     one (a physical count, or old stock not in the system). Nothing is
 *     saved: the same parameters drive the screen and the PDF.
 */
class ProfitLossRequest extends LedgerFilterRequest
{
    private const MAX_STOCK = 999999999999.99;

    /** @return array<string, mixed> */
    protected function extraRules(): array
    {
        $stock = ['nullable', 'numeric', 'min:0', 'max:' . self::MAX_STOCK];

        return [
            // Replace the shared nullable rules: both ends are required here.
            'date_from'    => ['required', 'date_format:Y-m-d'],
            'date_to'      => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],

            'compare'      => ['nullable', 'boolean'],
            'compare_from' => ['nullable', 'date_format:Y-m-d', 'required_with:compare_to'],
            'compare_to'   => ['nullable', 'date_format:Y-m-d', 'required_with:compare_from', 'after_or_equal:compare_from'],

            'opening_stock'         => $stock,
            'closing_stock'         => $stock,
            'compare_opening_stock' => $stock,
            'compare_closing_stock' => $stock,
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'date_from.required'          => 'Choose the From date.',
            'date_to.required'            => 'Choose the To date.',
            'compare_to.after_or_equal'   => 'The comparative To date cannot be before its From date.',
            'opening_stock.min'           => 'Stock cannot be negative.',
            'closing_stock.min'           => 'Stock cannot be negative.',
            'compare_opening_stock.min'   => 'Stock cannot be negative.',
            'compare_closing_stock.min'   => 'Stock cannot be negative.',
        ];
    }

    /**
     * @param  array<string, mixed> $validated
     * @return array<string, mixed>
     */
    protected function extraFilters(array $validated): array
    {
        $money = fn (string $key): ?float => isset($validated[$key]) && $validated[$key] !== ''
            ? (float) $validated[$key]
            : null;

        return [
            'compare'      => (bool) ($validated['compare'] ?? false),
            'compare_from' => $validated['compare_from'] ?? null,
            'compare_to'   => $validated['compare_to'] ?? null,
            'stock'        => [
                'opening'         => $money('opening_stock'),
                'closing'         => $money('closing_stock'),
                'compare_opening' => $money('compare_opening_stock'),
                'compare_closing' => $money('compare_closing_stock'),
            ],
        ];
    }
}
