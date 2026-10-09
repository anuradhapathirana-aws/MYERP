<?php

declare(strict_types=1);

namespace Modules\Finance\DTOs;

use Modules\Finance\Enums\AccountType;
use Modules\Finance\Http\Requests\AccountCategoryRequest;

final class AccountCategoryData
{
    public function __construct(
        public readonly AccountType $account_type,
        public readonly string      $category_name,
        public readonly ?string     $description,
        public readonly bool        $is_active,
    ) {}

    public static function fromRequest(AccountCategoryRequest $request): self
    {
        return new self(
            account_type:  AccountType::from((string) $request->input('account_type')),
            category_name: trim((string) $request->input('category_name')),
            description:   self::nullableTrim($request->input('description')),
            is_active:     (bool) $request->input('is_active', true),
        );
    }

    private static function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
