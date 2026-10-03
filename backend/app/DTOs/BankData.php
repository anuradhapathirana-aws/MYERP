<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Http\Requests\BankRequest;

final class BankData
{
    public function __construct(
        public readonly string  $bank_name,
        public readonly string  $bank_code,
        public readonly ?string $address,
        public readonly bool    $is_active,
    ) {}

    public static function fromRequest(BankRequest $request): self
    {
        $address = $request->input('address');
        $address = is_string($address) ? trim($address) : $address;

        return new self(
            bank_name: trim((string) $request->input('bank_name')),
            bank_code: strtoupper(trim((string) $request->input('bank_code'))),
            address:   $address === '' || $address === null ? null : (string) $address,
            is_active: (bool) $request->input('is_active', true),
        );
    }
}
