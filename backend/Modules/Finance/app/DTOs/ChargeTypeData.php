<?php

declare(strict_types=1);

namespace Modules\Finance\DTOs;

use Modules\Finance\Enums\ChargeTreatment;
use Modules\Finance\Http\Requests\ChargeTypeRequest;

final class ChargeTypeData
{
    public function __construct(
        public readonly string          $code,
        public readonly string          $name,
        public readonly ChargeTreatment $treatment,
        public readonly ?int            $ledger_account_id,
        public readonly ?int            $fob_expense_type_id,
        public readonly ?int            $cif_expense_type_id,
        public readonly bool            $is_active,
        public readonly int             $sort_order,
    ) {}

    public static function fromRequest(ChargeTypeRequest $request): self
    {
        $v         = $request->validated();
        $treatment = ChargeTreatment::from($v['treatment']);
        $id        = static fn (mixed $value): ?int => $value === null || $value === '' ? null : (int) $value;

        return new self(
            code:                strtoupper(trim($v['code'])),
            name:                trim($v['name']),
            treatment:           $treatment,
            ledger_account_id:   $id($v['ledger_account_id'] ?? null),
            // An expense never reaches a costing, so it keeps no Costing rows.
            fob_expense_type_id: $treatment->linksToGrn() ? $id($v['fob_expense_type_id'] ?? null) : null,
            cif_expense_type_id: $treatment->linksToGrn() ? $id($v['cif_expense_type_id'] ?? null) : null,
            is_active:           (bool) ($v['is_active'] ?? true),
            sort_order:          (int) ($v['sort_order'] ?? 0),
        );
    }
}
