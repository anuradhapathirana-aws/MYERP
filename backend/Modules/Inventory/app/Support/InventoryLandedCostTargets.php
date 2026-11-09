<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

use App\Contracts\LandedCostTargets;
use App\Services\SettingsService;
use Modules\Inventory\Enums\GrnStatus;
use Modules\Inventory\Models\CostingExpenseType;
use Modules\Inventory\Models\GoodsReceivedNote;

/**
 * Inventory's answer to "what can a bill's landed cost belong to": confirmed
 * GRNs (the shipment) and the Costing expense rows a charge fills.
 */
final class InventoryLandedCostTargets implements LandedCostTargets
{
    public function __construct(private readonly SettingsService $settings) {}

    public function isAvailable(): bool
    {
        return $this->settings->isEnabled('module.inventory');
    }

    public function searchGrns(?string $term, int $limit = 20): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        $query = $this->confirmedGrns()->orderByDesc('grn_date')->orderByDesc('id')->limit(max(1, min($limit, 100)));

        if ($term !== null && $term !== '') {
            $like = '%' . addcslashes($term, '%_\\') . '%';
            $query->where(fn ($q) => $q
                ->where('grn_no', 'like', $like)
                ->orWhereHas('supplier', fn ($s) => $s->where('supplier_name', 'like', $like)));
        }

        return $query->get()->map(fn (GoodsReceivedNote $grn): array => $this->row($grn))->values()->all();
    }

    public function grnsByIds(array $ids): array
    {
        if ($ids === [] || ! $this->isAvailable()) {
            return [];
        }

        return $this->confirmedGrns()
            ->whereKey(array_map('intval', $ids))
            ->get()
            ->mapWithKeys(fn (GoodsReceivedNote $grn): array => [(int) $grn->id => $this->row($grn)])
            ->all();
    }

    public function costingRows(): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        return CostingExpenseType::query()
            ->active()
            ->orderBy('costing_type')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'costing_type'])
            ->map(fn (CostingExpenseType $t): array => [
                'id'           => (int) $t->id,
                'name'         => $t->name,
                'costing_type' => $t->costing_type->value,
            ])
            ->values()
            ->all();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<GoodsReceivedNote> */
    private function confirmedGrns(): \Illuminate\Database\Eloquent\Builder
    {
        return GoodsReceivedNote::query()
            ->with('supplier:id,supplier_name')
            ->where('status', GrnStatus::Confirmed->value)
            ->select(['id', 'grn_no', 'grn_date', 'supplier_id', 'total_amount']);
    }

    /** @return array{id:int, grn_no:string, grn_date:?string, supplier_name:?string, total_amount:float} */
    private function row(GoodsReceivedNote $grn): array
    {
        return [
            'id'            => (int) $grn->id,
            'grn_no'        => $grn->grn_no,
            'grn_date'      => $grn->grn_date?->toDateString(),
            'supplier_name' => $grn->supplier?->supplier_name,
            'total_amount'  => (float) $grn->total_amount,
        ];
    }
}
