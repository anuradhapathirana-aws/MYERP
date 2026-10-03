<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Http\Requests\SupplierGroupRequest;

final class SupplierGroupData
{
    public function __construct(
        public readonly string  $code,
        public readonly string  $name,
        public readonly ?string $description,
        public readonly ?int    $default_payable_account_id,
        public readonly ?int    $default_expense_account_id,
        public readonly bool    $is_active,
        public readonly int     $sort_order,
    ) {}

    public static function fromRequest(SupplierGroupRequest $request): self
    {
        $payable = $request->input('default_payable_account_id');
        $expense = $request->input('default_expense_account_id');

        return new self(
            // Codes are normalised so uniqueness cannot be defeated by case or
            // surrounding whitespace.
            code:                       strtoupper(trim((string) $request->input('code'))),
            name:                       trim((string) $request->input('name')),
            description:                self::nullableString($request->input('description')),
            default_payable_account_id: $payable === null || $payable === '' ? null : (int) $payable,
            default_expense_account_id: $expense === null || $expense === '' ? null : (int) $expense,
            is_active:                  (bool) $request->input('is_active', true),
            sort_order:                 (int) $request->input('sort_order', 0),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === '' || $value === null ? null : (string) $value;
    }
}
