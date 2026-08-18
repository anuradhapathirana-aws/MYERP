<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Http\Requests\StoreCostingRequest;
use Modules\Inventory\Http\Requests\UpdateCostingRequest;

final class CostingData
{
    /**
     * @param array<int> $grnIds
     * @param array<array{
     *     grn_item_id: int,
     *     margin_amount_base: ?float,
     *     sscl_pct: ?float,
     *     vat_pct: ?float,
     *     expenses: array<array{expense_type_id: int, amount: float}>
     * }> $items Per-product costing inputs. EVERY monetary amount is quoted per
     *           the product's BASE (stocking) unit — margin is an addon amount,
     *           expenses are one amount per expense type of the costing's FOB/CIF
     *           list, and sscl_pct/vat_pct override the header defaults when set.
     */
    public function __construct(
        public readonly int     $supplierId,
        public readonly string  $costingType,
        public readonly array   $grnIds,
        public readonly ?string $billOfLading,
        public readonly ?string $expectedDate,
        public readonly ?string $transactionDate,
        public readonly ?string $note,
        public readonly bool    $applySscl,
        public readonly float   $ssclPct,
        public readonly bool    $applyVat,
        public readonly float   $vatPct,
        public readonly array   $items,
    ) {}

    public static function fromRequest(
        StoreCostingRequest|UpdateCostingRequest $request,
    ): self {
        return new self(
            supplierId:      (int) $request->validated('supplier_id'),
            costingType:     $request->validated('costing_type'),
            grnIds:          array_map('intval', (array) ($request->validated('grn_ids') ?? [])),
            billOfLading:    $request->validated('bill_of_lading'),
            expectedDate:    $request->validated('expected_date'),
            transactionDate: $request->validated('transaction_date'),
            note:            $request->validated('note'),
            applySscl:       (bool) ($request->validated('apply_sscl') ?? true),
            ssclPct:         (float) ($request->validated('sscl_pct') ?? 1.25),
            applyVat:        (bool) ($request->validated('apply_vat') ?? true),
            vatPct:          (float) ($request->validated('vat_pct') ?? 18),
            items:           (array) ($request->validated('items') ?? []),
        );
    }
}
