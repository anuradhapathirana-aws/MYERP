<?php

declare(strict_types=1);

namespace Modules\Finance\DTOs;

use Modules\Finance\Http\Requests\ControlAccountRequest;

final class ControlAccountData
{
    public function __construct(
        public readonly int     $account_category_id,
        public readonly string  $control_account_name,
        public readonly ?string $description,
        public readonly bool    $is_active,
    ) {}

    public static function fromRequest(ControlAccountRequest $request): self
    {
        $description = $request->input('description');
        $description = is_string($description) ? trim($description) : null;

        return new self(
            account_category_id:  (int) $request->input('account_category_id'),
            control_account_name: trim((string) $request->input('control_account_name')),
            description:          $description === '' ? null : $description,
            is_active:            (bool) $request->input('is_active', true),
        );
    }
}
