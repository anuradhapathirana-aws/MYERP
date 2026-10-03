<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Http\Requests\BankBranchRequest;

final class BankBranchData
{
    public function __construct(
        public readonly int     $bank_id,
        public readonly string  $branch_name,
        public readonly string  $branch_code,
        public readonly ?string $swift_code,
        public readonly ?string $address,
        public readonly bool    $is_active,
    ) {}

    public static function fromRequest(BankBranchRequest $request): self
    {
        return new self(
            bank_id:     (int) $request->input('bank_id'),
            branch_name: trim((string) $request->input('branch_name')),
            branch_code: strtoupper(trim((string) $request->input('branch_code'))),
            swift_code:  self::nullableUpper($request->input('swift_code')),
            address:     self::nullableString($request->input('address')),
            is_active:   (bool) $request->input('is_active', true),
        );
    }

    private static function nullableUpper(mixed $value): ?string
    {
        $value = self::nullableString($value);

        return $value === null ? null : strtoupper($value);
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === '' || $value === null ? null : (string) $value;
    }
}
