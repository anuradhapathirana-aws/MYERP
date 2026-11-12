<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Contracts\LandedChargeSource;
use App\DTOs\LandedCharge;
use App\Models\Tax;
use App\Services\TaxService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\DTOs\CostingData;
use Modules\Inventory\Enums\CostingStatus;
use Modules\Inventory\Enums\GrnStatus;
use Modules\Inventory\Models\Costing;
use Modules\Inventory\Models\CostingBillCharge;
use Modules\Inventory\Models\CostingExpense;
use Modules\Inventory\Models\CostingGrn;
use Modules\Inventory\Models\CostingItem;
use Modules\Inventory\Models\CostingItemExpense;
use Modules\Inventory\Models\CostingExpenseType;
use Modules\Inventory\Models\GoodsReceivedNote;
use Modules\Inventory\Models\GoodsReceivedNoteItem;

/**
 * GRN-based landed costing → per-product selling prices.
 *
 * The costing loads every item line of the selected GRNs (GRN unit_price =
 * PURCHASING price = the line's FOB/CIF value) and builds each line's selling
 * price from ITS OWN typed inputs — every amount quoted PER BASE (stocking) UNIT:
 *
 *   fob_cif      = grn unit_price ÷ conversion_factor          (per base unit)
 *   expenses     = Σ typed amounts per expense type of the costing's FOB/CIF list
 *   sscl         = sscl% × (fob_cif + expenses + margin); default 1.25 — rounded UP to the whole rupee
 *   before_tax   = fob_cif + expenses + margin + sscl
 *   after_tax    = before_tax + VAT%                  (= selling_price)
 *
 * Before- and after-tax are both persisted so VAT and non-VAT invoices can later
 * pick the figure they need. The full per-unit build-up is stored on
 * inv_costing_items (typed expense rows on inv_costing_item_expenses) so the user
 * can see exactly what happened to the price. Confirm keeps the one-GRN-per-
 * confirmed-costing lock and mirrors landed/selling onto the product price
 * list via ProductPricingService; sales orders price each roll from its own
 * shipment's confirmed costing (old stock at old price, new at new).
 *
 * Pull from bills: confirmed supplier-bill lines linked to the costing's GRNs
 * (freight, duty, clearing... entered in Finance) can be added. Each is spread
 * over the products BY VALUE -- per base unit that is fob_cif x (bill amount /
 * purchase value) -- into the expense row its charge type maps to, beside any
 * typed (estimate) amounts. Bills come through LandedChargeSource, never from
 * a fin_ table, so Inventory works the same with Finance switched off.
 */
class CostingService
{
    public function __construct(
        private readonly ProductPricingService $pricing,
        private readonly UnitConversionService $units,
        private readonly LandedChargeSource $landedCharges,
    ) {
    }

    /**
     * Guard: the GRN line's frozen conversion must still agree with the product's
     * current Stocking UOM.
     *
     * A line freezes `conversion_factor` at GRN confirm, against the base unit the
     * product had THEN. Nothing stops the base unit being changed afterwards, and the
     * line has no memory of which unit it froze against — so a product received in kg
     * with factor 1, later re-based to g, now claims 1 kg = 1 g. Costing it would file a
     * per-kg price as a per-gram one and sell the goods for a thousandth of their price.
     *
     * Refuse, loudly and specifically. A blocked costing is a nuisance; a silently
     * 1000×-wrong selling price on live stock is not.
     */
    private function assertConversionIsSound(GoodsReceivedNoteItem $item): void
    {
        $recvUnitId = $item->unit_id !== null ? (int) $item->unit_id : null;
        $baseUnitId = $item->product?->base_unit_type_id !== null ? (int) $item->product->base_unit_type_id : null;

        if ($recvUnitId === null || $baseUnitId === null) {
            return; // Pre-UOM line with nothing to check against.
        }

        $expected   = $recvUnitId === $baseUnitId ? 1.0 : $this->units->tryFactor($recvUnitId, $baseUnitId);
        $product    = $item->product?->name ?? "Product #{$item->product_id}";
        $recvSymbol = $item->unit?->symbol ?? "unit #{$recvUnitId}";
        $baseSymbol = $item->product?->baseUnit?->symbol ?? "unit #{$baseUnitId}";

        // No rate at all: the goods were received in a unit that cannot even be expressed
        // in the unit they are stocked in (metres against grams). There is no selling
        // price per gram for something measured in metres.
        abort_if($expected === null, 422, sprintf(
            'Cannot cost "%s": it was received in %s but is stocked in %s, and there is no conversion between them. '
            . 'Fix the product\'s Stocking UOM, or add the %s → %s rate in Unit Conversions.',
            $product, $recvSymbol, $baseSymbol, $recvSymbol, $baseSymbol,
        ));

        $frozen = (float) $item->conversion_factor ?: 1.0;

        if (abs($expected - $frozen) <= 1e-6 * max(1.0, abs($expected))) {
            return;
        }

        $trim = static fn (float $n): string => rtrim(rtrim(number_format($n, 6, '.', ''), '0'), '.');

        abort(422, sprintf(
            'Cannot cost "%s": it was received in %s but is stocked in %s, and its GRN line froze a conversion of %s where %s is expected. '
            . 'Its Stocking UOM was changed after the goods were received, so its stock and prices no longer mean what they say. '
            . 'Fix the product\'s Stocking UOM (or the %s → %s conversion rate) before costing this shipment.',
            $product, $recvSymbol, $baseSymbol, $trim($frozen), $trim($expected), $recvSymbol, $baseSymbol,
        ));
    }

    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $query = Costing::with(['supplier'])
            ->orderByDesc('id');

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term): void {
                $q->where('document_no', 'like', $term)
                  ->orWhere('reference_no', 'like', $term)
                  ->orWhere('bill_of_lading', 'like', $term);
            });
        }

        if (!empty($filters['supplier_id'])) {
            $query->where('supplier_id', (int) $filters['supplier_id']);
        }

        if (!empty($filters['costing_type'])) {
            $query->where('costing_type', $filters['costing_type']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('transaction_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('transaction_date', '<=', $filters['date_to']);
        }

        return $query->paginate($perPage);
    }

    public function find(int $id): Costing
    {
        return Costing::with([
            'supplier',
            'costingGrns.grn.items',
            'items.product:id,name,product_code',
            'items.attribute:id,attribute_name',
            'items.variant:id,sku,variant_label',
            'items.unit:id,symbol,unit_position',
            'items.baseUnit:id,symbol,unit_position',
            'items.grn:id,grn_no',
            'items.expenses.expenseType:id,name',
            'billCharges.expenseType:id,name',
        ])->findOrFail($id);
    }

    public function create(CostingData $data): Costing
    {
        return DB::transaction(function () use ($data): Costing {
            $grns      = $this->loadGrns($data->grnIds);
            $charges   = $this->resolveBillCharges($data->billChargeIds, $grns, $data->costingType, null);
            $breakdown = $this->computeBreakdown($grns, $data, null, $charges);

            $costing = Costing::create(array_merge(
                [
                    'document_no'  => $this->generateDocumentNo(),
                    'reference_no' => $this->generateReferenceNo(),
                    'status'       => CostingStatus::Draft,
                    'created_by'   => auth()->id(),
                ],
                $this->headerAttributes($data, $breakdown['summary']),
            ));

            $this->syncGrns($costing, $grns);
            $this->syncItems($costing, $breakdown['items']);
            $this->syncBillCharges($costing, $charges);

            return $this->find($costing->id);
        });
    }

    public function update(Costing $costing, CostingData $data): Costing
    {
        if ($costing->status !== CostingStatus::Draft) {
            abort(422, 'Only draft costings can be edited.');
        }

        return DB::transaction(function () use ($costing, $data): Costing {
            $grns      = $this->loadGrns($data->grnIds);
            $charges   = $this->resolveBillCharges($data->billChargeIds, $grns, $data->costingType, (int) $costing->id);
            $breakdown = $this->computeBreakdown($grns, $data, null, $charges);

            $costing->update($this->headerAttributes($data, $breakdown['summary']));

            $this->syncGrns($costing, $grns);
            $this->syncItems($costing, $breakdown['items']);
            $this->syncBillCharges($costing, $charges);

            return $this->find($costing->id);
        });
    }

    public function confirm(Costing $costing): Costing
    {
        return DB::transaction(function () use ($costing): Costing {
            // Re-fetch with a row lock so two concurrent confirm requests can't both pass
            $locked = Costing::lockForUpdate()->findOrFail($costing->id);

            if ($locked->status !== CostingStatus::Draft) {
                abort(422, 'Only draft costings can be confirmed.');
            }

            // Collect the GRN IDs attached to this costing
            $grnIds = CostingGrn::where('costing_id', $locked->id)->pluck('grn_id');

            if ($grnIds->isNotEmpty()) {
                // Find any of those GRNs that are already locked in a different confirmed costing
                $conflicting = CostingGrn::whereIn('grn_id', $grnIds)
                    ->where('costing_id', '!=', $locked->id)
                    ->whereIn(
                        'costing_id',
                        Costing::where('status', CostingStatus::Confirmed)->select('id'),
                    )
                    ->with('grn:id,grn_no')
                    ->get();

                if ($conflicting->isNotEmpty()) {
                    $grnNos = $conflicting->map(fn (CostingGrn $cg) => $cg->grn?->grn_no ?? "GRN#{$cg->grn_id}")
                        ->unique()
                        ->join(', ');

                    abort(422, "Cannot confirm: the following GRNs are already costed in another confirmed costing — {$grnNos}.");
                }
            }

            $locked->update([
                'status'       => CostingStatus::Confirmed,
                'confirmed_at' => now(),
            ]);

            // Price-list write-back: newest costed shipment wins. Iterate in id
            // order so, when one product appears on several lines, the last
            // line's price lands on the price list; individual rolls still
            // price from their own line via resolvePieceSellingPrice().
            //
            // The *_base figures go over, not the receiving-unit ones: the price list
            // and every sales document speak the stocking UOM, and these were computed
            // with the GRN line's frozen factor.
            //
            // A variant line (Red / M) writes its own variant price instead — otherwise
            // the last variant costed would reprice every variant of the product.
            CostingItem::where('costing_id', $locked->id)
                ->orderBy('id')
                ->get()
                ->each(fn (CostingItem $item) => $item->variant_id !== null
                    ? $this->pricing->syncVariantFromCosting(
                        (int) $item->product_id,
                        (int) $item->variant_id,
                        $item->base_unit_id !== null ? (int) $item->base_unit_id : null,
                        (float) $item->selling_price_base,
                    )
                    : $this->pricing->syncFromCosting(
                        (int) $item->product_id,
                        $item->base_unit_id !== null ? (int) $item->base_unit_id : null,
                        (float) $item->landed_unit_cost_base,
                        (float) $item->selling_price_base,
                    ));

            return $this->find($locked->id);
        });
    }

    public function delete(Costing $costing): void
    {
        if ($costing->status !== CostingStatus::Draft) {
            abort(422, 'Only draft costings can be deleted.');
        }

        DB::transaction(function () use ($costing): void {
            // Releases the bills it pulled, so they can be voided or pulled elsewhere.
            CostingBillCharge::where('costing_id', $costing->id)->delete();
            $costing->delete();
        });
    }

    /** Preview next document number (lock-free, display only) */
    public function nextDocumentNo(): string
    {
        $year   = now()->year;
        $prefix = "CST-{$year}-";

        $last = Costing::withTrashed()
            ->where('document_no', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('document_no');

        $next = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** Preview next reference number (lock-free, display only) */
    public function nextReferenceNo(): string
    {
        $prefix = 'CRef-';

        $last = Costing::withTrashed()
            ->where('reference_no', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('reference_no');

        $next = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Returns confirmed GRNs for a supplier that are not yet attached to a confirmed costing.
     *
     * @return array<int, mixed>
     */
    public function getGrnsBySupplier(int $supplierId): array
    {
        // IDs of GRNs already locked in confirmed costings
        $usedGrnIds = CostingGrn::whereIn(
            'costing_id',
            Costing::where('status', CostingStatus::Confirmed)->select('id'),
        )->pluck('grn_id')->all();

        $grns = GoodsReceivedNote::with([
            'items.product:id,name,product_code,base_unit_type_id',
            'items.product.baseUnit:id,symbol,unit_position',
            'items.attribute:id,attribute_name',
            'items.variant:id,sku,variant_label',
            'items.unit:id,symbol,unit_position',
            'purchaseOrder',
        ])
            ->where('supplier_id', $supplierId)
            ->where('status', GrnStatus::Confirmed)
            ->whereNotIn('id', $usedGrnIds)
            ->orderByDesc('grn_date')
            ->get();

        return $grns->map(fn (GoodsReceivedNote $grn) => [
            'id'           => $grn->id,
            'grn_no'       => $grn->grn_no,
            'grn_date'     => $grn->grn_date?->toDateString(),
            'po_no'        => $grn->purchaseOrder?->po_no,
            'total_amount' => (float) $grn->total_amount,
            'total_items'  => $grn->items->sum('quantity_received'),
            // Item lines feed the client-side product breakdown (live math). The frozen
            // conversion travels with them so the form can show the same receiving-unit →
            // stocking-UOM restatement the server will persist.
            'items'        => $grn->items->map(fn ($item) => [
                'id'                 => $item->id,
                'product_id'         => $item->product_id,
                'product_name'       => $item->product?->name,
                'product_code'       => $item->product?->product_code,
                'color'              => $item->variant?->variant_label ?? $item->attribute?->attribute_name,
                'quantity'           => (float) $item->quantity_received,
                'unit_price'         => (float) $item->unit_price,
                'unit_symbol'        => $item->unit?->symbol,
                'unit_position'      => $item->unit?->unit_position,
                'conversion_factor'  => (float) $item->conversion_factor ?: 1.0,
                'base_quantity'      => (float) $item->base_quantity,
                'base_unit_symbol'   => $item->product?->baseUnit?->symbol,
                'base_unit_position' => $item->product?->baseUnit?->unit_position,
            ])->values()->all(),
        ])->all();
    }

    /**
     * Expense types filtered by costing type.
     *
     * @return array<int, mixed>
     */
    public function getExpenseTypes(string $costingType): array
    {
        return CostingExpenseType::active()
            ->forType($costingType)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (CostingExpenseType $t) => [
                'id'           => $t->id,
                'name'         => $t->name,
                'costing_type' => $t->costing_type->value,
                'sort_order'   => $t->sort_order,
            ])->all();
    }

    /**
     * Stateless breakdown preview for the form's live math — same compute
     * path as create/update, nothing persisted.
     *
     * @param array<string, mixed> $input
     * @return array{items: array<int, array<string, mixed>>, summary: array<string, float>}
     */
    public function preview(array $input): array
    {
        $grns    = $this->loadGrns(array_map('intval', (array) ($input['grn_ids'] ?? [])));
        $charges = $this->resolveBillCharges(
            array_map('intval', (array) ($input['bill_charge_ids'] ?? [])),
            $grns,
            ($input['costing_type'] ?? 'fob') === 'cif' ? 'cif' : 'fob',
            isset($input['costing_id']) ? (int) $input['costing_id'] : null,
        );

        return $this->computeBreakdown($grns, null, [
            'apply_sscl' => (bool) ($input['apply_sscl'] ?? true),
            'sscl_pct'   => (float) ($input['sscl_pct'] ?? app(TaxService::class)->configuredRate(Tax::SSCL)),
            'apply_vat'  => (bool) ($input['apply_vat'] ?? true),
            'vat_pct'    => (float) ($input['vat_pct'] ?? app(TaxService::class)->rate(Tax::VAT)),
            'items'      => (array) ($input['items'] ?? []),
        ], $charges);
    }

    /**
     * What "Pull from bills" offers for these GRNs: every confirmed landed-cost
     * bill line linked to them, each marked
     *
     *   available  -- can be pulled (included = already in this costing)
     *   unmapped   -- its charge type has no Costing row for this FOB/CIF list
     *   elsewhere  -- already pulled into another costing (named)
     *
     * @param  list<int> $grnIds
     * @return array{available: bool, charges: list<array<string, mixed>>}
     */
    public function billChargeOptions(array $grnIds, string $costingType, ?int $costingId): array
    {
        if (! $this->landedCharges->isAvailable()) {
            return ['available' => false, 'charges' => []];
        }

        $charges = $this->landedCharges->chargesForGrns($grnIds);
        $rows    = $this->activeExpenseRows($costingType);
        $taken   = $this->pulledElsewhere(array_map(fn (LandedCharge $c) => $c->sourceId, $charges), $costingId);

        return [
            'available' => true,
            'charges'   => array_map(function (LandedCharge $c) use ($costingType, $rows, $taken): array {
                $typeId = $c->expenseTypeFor($costingType);

                return $c->toArray() + [
                    'expense_type_id'   => $typeId !== null && isset($rows[$typeId]) ? $typeId : null,
                    'expense_type_name' => $typeId !== null ? ($rows[$typeId] ?? null) : null,
                    'status'            => isset($taken[$c->sourceId]) ? 'elsewhere'
                        : ($typeId === null || ! isset($rows[$typeId]) ? 'unmapped' : 'available'),
                    'elsewhere'         => $taken[$c->sourceId] ?? null,
                ];
            }, $charges),
        ];
    }

    /**
     * Bills linked to this costing's GRNs that are not in it -- a bill that
     * arrived after the costing was built. Shown as a warning; a confirmed
     * costing is final (its prices are already live).
     *
     * @return list<array<string, mixed>>
     */
    public function billsNotIncluded(Costing $costing): array
    {
        $grnIds   = $costing->costingGrns()->pluck('grn_id')->map(fn ($id) => (int) $id)->all();
        $included = $costing->billCharges()->pluck('source_id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_map(
            fn (LandedCharge $c): array => $c->toArray(),
            array_filter(
                $this->landedCharges->chargesForGrns($grnIds),
                fn (LandedCharge $c): bool => ! in_array($c->sourceId, $included, true),
            ),
        ));
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Single source of truth for the costing formula. Builds the per-product
     * breakdown from the selected GRNs' item lines plus each line's own typed
     * costing inputs — expenses, SSCL %, margin amount and VAT %, ALL per the
     * product's BASE (stocking) unit:
     *
     *   before_tax = fob/cif + Σ expenses + margin + ceil(sscl% × (fob/cif + expenses + margin))
     *   after_tax  = before_tax + vat%              (= selling_price)
     *
     * There is no cross-line apportionment any more: every line prices itself.
     * The build-up runs per base unit (the denomination the user typed in);
     * the legacy per-receiving-unit columns are the same money × factor.
     *
     * @param array<string, mixed>|null $raw preview-mode inputs when no DTO
     * @return array{items: array<int, array<string, mixed>>, summary: array<string, float>}
     */
    private function computeBreakdown(Collection $grns, ?CostingData $data, ?array $raw = null, array $charges = []): array
    {
        $applySscl = $data?->applySscl ?? $raw['apply_sscl'];
        $ssclPct   = $data?->ssclPct   ?? $raw['sscl_pct'];
        $applyVat  = $data?->applyVat  ?? $raw['apply_vat'];
        $vatPct    = $data?->vatPct    ?? $raw['vat_pct'];
        $inputs    = collect($data?->items ?? $raw['items'])->keyBy('grn_item_id');

        /** @var Collection<int, GoodsReceivedNoteItem> $grnItems */
        $grnItems = $grns->flatMap(fn (GoodsReceivedNote $grn) => $grn->items);

        $totalQty = (float) $grnItems->sum('quantity_received');

        // Pulled bills, spread by value: each expense row's bill total as a
        // share of the purchase value. A line then carries fob_cif x share per
        // base unit -- exactly its value's part of the bill.
        $billRates = $this->billRates($charges, $grnItems);

        $items = $grnItems->map(function (GoodsReceivedNoteItem $item) use (
            $applySscl, $ssclPct, $applyVat, $vatPct, $inputs, $billRates,
        ): array {
            $this->assertConversionIsSound($item);

            $qty       = (float) $item->quantity_received;
            $unitPrice = (float) $item->unit_price;

            // The GRN line froze this at confirm time — reuse it rather than re-reading
            // inv_unit_conversions, whose rates are editable and may since have moved.
            $factor  = (float) $item->conversion_factor ?: 1.0;
            $baseQty = (float) $item->base_quantity ?: $qty * $factor;

            $input = $inputs->get($item->id, []);

            // FOB/CIF value per base unit = the GRN purchasing price restated
            $fobBase = $factor > 0.0 ? $unitPrice / $factor : $unitPrice;

            // Typed expense amounts (per base unit) against this line -- estimates
            $expenseRows = collect($input['expenses'] ?? [])
                ->filter(fn (array $e): bool => !empty($e['expense_type_id']) && (float) ($e['amount'] ?? 0) > 0)
                ->map(fn (array $e): array => [
                    'expense_type_id' => (int) $e['expense_type_id'],
                    'source'          => CostingItemExpense::SOURCE_MANUAL,
                    'amount'          => round((float) $e['amount'], 8),
                ])
                ->values();

            // This line's share of the pulled bills, per base unit
            foreach ($billRates as $typeId => $rate) {
                $expenseRows->push([
                    'expense_type_id' => (int) $typeId,
                    'source'          => CostingItemExpense::SOURCE_BILL,
                    'amount'          => round($fobBase * $rate, 8),
                ]);
            }

            $expBase  = (float) $expenseRows->sum('amount');
            $billBase = (float) $expenseRows->where('source', CostingItemExpense::SOURCE_BILL)->sum('amount');

            $num = static fn (string $key): ?float =>
                isset($input[$key]) && $input[$key] !== null && $input[$key] !== ''
                    ? (float) $input[$key] : null;

            $lineSsclPct = $num('sscl_pct');
            $lineVatPct  = $num('vat_pct');
            $marginBase  = $num('margin_amount_base') ?? 0.0;

            $effSsclPct = $applySscl ? ($lineSsclPct ?? $ssclPct) : 0.0;
            $effVatPct  = $applyVat ? ($lineVatPct ?? $vatPct) : 0.0;

            // SSCL is levied on the whole cost-plus-margin sum (FOB/CIF + expenses + margin),
            // then rounded UP to the whole rupee per base unit — the levy is billed in
            // whole rupees, never cents. round() first so float dust cannot bump an
            // exact 6.00 into 7.
            $ssclBase      = (float) ceil(round(($fobBase + $expBase + $marginBase) * ($effSsclPct / 100), 6));
            $beforeTaxBase = $fobBase + $expBase + $marginBase + $ssclBase;
            $vatBase       = $beforeTaxBase * ($effVatPct / 100);
            $afterTaxBase  = $beforeTaxBase + $vatBase;

            // Legacy columns stay denominated per the RECEIVING unit — same money × factor
            $toRecv = static fn (float $price): float => $price * $factor;

            return [
                // Persisted columns — per the RECEIVING unit unless suffixed _base
                'grn_id'                => (int) $item->grn_id,
                'grn_item_id'           => (int) $item->id,
                'product_id'            => (int) $item->product_id,
                'attribute_id'          => $item->attribute_id !== null ? (int) $item->attribute_id : null,
                'variant_id'            => $item->variant_id !== null ? (int) $item->variant_id : null,
                'unit_id'               => $item->unit_id !== null ? (int) $item->unit_id : null,
                'base_unit_id'          => $item->product?->base_unit_type_id !== null ? (int) $item->product->base_unit_type_id : null,
                'quantity'              => $qty,
                'conversion_factor'     => $factor,
                'base_quantity'         => round($baseQty, 6),
                'unit_price'            => round($unitPrice, 8),
                'charge_portion'        => round($toRecv($expBase), 8),
                'landed_unit_cost'      => round($unitPrice + $toRecv($expBase), 8),
                'landed_unit_cost_base' => round($fobBase + $expBase, 8),
                'expense_total_base'    => round($expBase, 8),
                'margin_pct'            => null,
                'margin_amount'         => round($toRecv($marginBase), 8),
                'margin_amount_base'    => round($marginBase, 8),
                'sscl_pct'              => $lineSsclPct,
                'sscl_amount'           => round($toRecv($ssclBase), 8),
                'sscl_amount_base'      => round($ssclBase, 8),
                'vat_pct'               => $lineVatPct,
                'vat_amount'            => round($toRecv($vatBase), 8),
                'vat_amount_base'       => round($vatBase, 8),
                'before_tax_price'      => round($toRecv($beforeTaxBase), 8),
                'before_tax_price_base' => round($beforeTaxBase, 8),
                'selling_price'         => round($toRecv($afterTaxBase), 8),
                'selling_price_base'    => round($afterTaxBase, 8),
                'is_price_overridden'   => false,
                // Typed expense rows — persisted to inv_costing_item_expenses by syncItems
                'expense_rows'          => $expenseRows->all(),
                // Display-only extras (preview payload; stripped before insert)
                'bill_expense_base'     => round($billBase, 8),
                'product_name'          => $item->product?->name,
                'product_code'          => $item->product?->product_code,
                'color'                 => $item->variant?->variant_label ?? $item->attribute?->attribute_name,
                'grn_no'                => $item->grn?->grn_no,
                'unit_symbol'           => $item->unit?->symbol,
                'unit_position'         => $item->unit?->unit_position,
                'base_unit_symbol'      => $item->product?->baseUnit?->symbol,
                'base_unit_position'    => $item->product?->baseUnit?->unit_position,
            ];
        })->values();

        // Full-quantity totals: Σ base_quantity × per-base-unit amount. (Equal to
        // Σ quantity × per-receiving-unit amount — same money, either denomination.)
        $sum = fn (string $key): float => (float) $items->sum(fn (array $i) => $i['base_quantity'] * $i[$key]);

        // Purchase value straight off the GRN lines — exact, no restatement round-trip
        $purchaseValue = (float) $items->sum(fn (array $i) => $i['quantity'] * $i['unit_price']);

        $summary = [
            'total_items'               => $totalQty,
            // Legacy columns, now derived: material cost = Σ GRN line values (FOB/CIF value)
            'material_cost'             => $purchaseValue,
            'raw_material_cost'         => $purchaseValue,
            'total_additional_expenses' => $sum('expense_total_base'),
            'total_landed_cost'         => $purchaseValue + $sum('expense_total_base'),
            'value_addition_pct'        => 0.0,
            'value_addition_amount'     => 0.0,
            // Landed + margin (pre-SSCL, pre-VAT)
            'fob_cif_cost'              => $purchaseValue + $sum('expense_total_base') + $sum('margin_amount_base'),
            'sscl_amount'               => $sum('sscl_amount_base'),
            // "Before Tax Value" — what non-VAT invoices will bill
            'gross_fob_cif_value'       => $sum('before_tax_price_base'),
            'vat_amount'                => $sum('vat_amount_base'),
            // "After Tax Value" — what VAT invoices will bill
            'total_price_with_vat'      => $sum('selling_price_base'),
        ];

        return ['items' => $items->all(), 'summary' => $summary];
    }

    /**
     * Header column values shared by create/update.
     *
     * @param array<string, float> $summary
     * @return array<string, mixed>
     */
    private function headerAttributes(CostingData $data, array $summary): array
    {
        return array_merge($summary, [
            'supplier_id'      => $data->supplierId,
            'costing_type'     => $data->costingType,
            'bill_of_lading'   => $data->billOfLading,
            'expected_date'    => $data->expectedDate,
            'transaction_date' => $data->transactionDate,
            'note'             => $data->note,
            'apply_sscl'       => $data->applySscl,
            'sscl_pct'         => $data->ssclPct,
            'apply_vat'        => $data->applyVat,
            'vat_pct'          => $data->vatPct,
        ]);
    }

    /** Atomically generate the next document number (must be called inside a DB transaction) */
    private function generateDocumentNo(): string
    {
        $year   = now()->year;
        $prefix = "CST-{$year}-";

        $last = Costing::withTrashed()
            ->where('document_no', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('document_no');

        $next = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** Atomically generate the next reference number (must be called inside a DB transaction) */
    private function generateReferenceNo(): string
    {
        $prefix = 'CRef-';

        $last = Costing::withTrashed()
            ->where('reference_no', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('reference_no');

        $next = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** @param array<int> $grnIds */
    private function loadGrns(array $grnIds): Collection
    {
        return GoodsReceivedNote::with([
            'items.product:id,name,product_code,base_unit_type_id',
            'items.product.baseUnit:id,symbol,unit_position',
            'items.attribute:id,attribute_name',
            'items.variant:id,sku,variant_label',
            'items.unit:id,symbol,unit_position',
        ])
            ->whereIn('id', $grnIds)
            ->get();
    }

    private function syncGrns(Costing $costing, Collection $grns): void
    {
        CostingGrn::where('costing_id', $costing->id)->delete();

        $rows = $grns->map(fn (GoodsReceivedNote $grn) => [
            'costing_id' => $costing->id,
            'grn_id'     => $grn->id,
            'grn_total'  => (float) $grn->total_amount,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        if (!empty($rows)) {
            CostingGrn::insert($rows);
        }
    }

    /**
     * Delete-and-reinsert the per-product breakdown rows and their typed
     * expense rows (draft edits rebuild). Legacy costing-level expense rows
     * (inv_costing_expenses) are cleared too — the redesign types expenses
     * per product line instead.
     *
     * @param array<int, array<string, mixed>> $items computeBreakdown() output
     */
    private function syncItems(Costing $costing, array $items): void
    {
        CostingItemExpense::where('costing_id', $costing->id)->delete();
        CostingItem::where('costing_id', $costing->id)->delete();
        CostingExpense::where('costing_id', $costing->id)->delete();

        $columns = [
            'grn_id', 'grn_item_id', 'product_id', 'attribute_id', 'variant_id', 'unit_id', 'base_unit_id',
            'quantity', 'conversion_factor', 'base_quantity',
            'unit_price', 'charge_portion', 'landed_unit_cost', 'landed_unit_cost_base',
            'expense_total_base',
            'margin_pct', 'margin_amount', 'margin_amount_base',
            'sscl_pct', 'sscl_amount', 'sscl_amount_base',
            'vat_pct', 'vat_amount', 'vat_amount_base',
            'before_tax_price', 'before_tax_price_base',
            'selling_price', 'selling_price_base', 'is_price_overridden',
        ];

        $rows = collect($items)->map(fn (array $item) => array_merge(
            array_intersect_key($item, array_flip($columns)),
            ['costing_id' => $costing->id, 'created_at' => now(), 'updated_at' => now()],
        ))->all();

        if (!empty($rows)) {
            CostingItem::insert($rows);
        }

        // The typed expense rows link to the freshly inserted item rows
        $idByGrnItem = CostingItem::where('costing_id', $costing->id)->pluck('id', 'grn_item_id');

        $expenseRows = [];
        foreach ($items as $item) {
            $itemId = $idByGrnItem[(int) $item['grn_item_id']] ?? null;
            if ($itemId === null) {
                continue;
            }
            foreach ($item['expense_rows'] ?? [] as $expense) {
                $expenseRows[] = [
                    'costing_id'      => $costing->id,
                    'costing_item_id' => (int) $itemId,
                    'grn_item_id'     => (int) $item['grn_item_id'],
                    'expense_type_id' => $expense['expense_type_id'],
                    'source'          => $expense['source'] ?? CostingItemExpense::SOURCE_MANUAL,
                    'amount'          => $expense['amount'],
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ];
            }
        }

        if (!empty($expenseRows)) {
            CostingItemExpense::insert($expenseRows);
        }
    }

    /**
     * The bills to pull, re-read from Finance (never trusted from the form) and
     * checked: still a confirmed landed cost, belongs to one of these GRNs,
     * has a Costing row on this FOB/CIF list, and is not in another costing.
     *
     * @param  list<int> $sourceIds
     * @return list<array{charge: LandedCharge, expense_type_id: int}>
     */
    private function resolveBillCharges(array $sourceIds, Collection $grns, string $costingType, ?int $costingId): array
    {
        $sourceIds = array_values(array_unique(array_map('intval', $sourceIds)));

        if ($sourceIds === []) {
            return [];
        }

        $charges = collect($this->landedCharges->chargesByIds($sourceIds))->keyBy(fn (LandedCharge $c) => $c->sourceId);
        $missing = array_diff($sourceIds, $charges->keys()->all());

        abort_if($missing !== [], 422, 'A pulled bill is no longer available (it was voided, or Finance is switched off). Pull from bills again.');

        $grnIds = $grns->pluck('id')->map(fn ($id) => (int) $id)->all();
        $rows   = $this->activeExpenseRows($costingType);
        $taken  = $this->pulledElsewhere($sourceIds, $costingId, lock: true);

        return $charges->values()->map(function (LandedCharge $c) use ($grnIds, $rows, $taken, $costingType): array {
            if (! in_array($c->grnId, $grnIds, true)) {
                abort(422, "{$c->billNo} ({$c->chargeName}) belongs to a GRN that is not in this costing.");
            }

            if (isset($taken[$c->sourceId])) {
                abort(422, "{$c->billNo} ({$c->chargeName}) is already in {$taken[$c->sourceId]}.");
            }

            $typeId = $c->expenseTypeFor($costingType);

            if ($typeId === null || ! isset($rows[$typeId])) {
                abort(422, "{$c->chargeName} has no " . strtoupper($costingType) . ' costing row. Set it in Finance > Charge Types.');
            }

            return ['charge' => $c, 'expense_type_id' => $typeId];
        })->all();
    }

    /**
     * Each expense row's pulled-bill total as a share of the purchase value.
     *
     * @param  list<array{charge: LandedCharge, expense_type_id: int}> $charges
     * @param  Collection<int, GoodsReceivedNoteItem> $grnItems
     * @return array<int, float> expense_type_id => share
     */
    private function billRates(array $charges, Collection $grnItems): array
    {
        if ($charges === []) {
            return [];
        }

        $purchaseValue = (float) $grnItems->sum(fn (GoodsReceivedNoteItem $i) => (float) $i->quantity_received * (float) $i->unit_price);

        abort_if($purchaseValue <= 0.0, 422, 'Bills are spread by value, and these GRNs have no value. Check the GRN prices.');

        $rates = [];

        foreach ($charges as ['charge' => $charge, 'expense_type_id' => $typeId]) {
            $rates[$typeId] = ($rates[$typeId] ?? 0.0) + $charge->amount / $purchaseValue;
        }

        return $rates;
    }

    /** @param list<array{charge: LandedCharge, expense_type_id: int}> $charges */
    private function syncBillCharges(Costing $costing, array $charges): void
    {
        CostingBillCharge::where('costing_id', $costing->id)->delete();

        foreach ($charges as ['charge' => $c, 'expense_type_id' => $typeId]) {
            CostingBillCharge::create([
                'costing_id'      => $costing->id,
                'source_type'     => CostingBillCharge::SOURCE_BILL_LINE,
                'source_id'       => $c->sourceId,
                'bill_no'         => $c->billNo,
                'bill_date'       => $c->billDate,
                'party_name'      => $c->partyName === null ? null : mb_substr($c->partyName, 0, 150),
                'charge_name'     => mb_substr($c->chargeName, 0, 100),
                'grn_id'          => $c->grnId,
                'expense_type_id' => $typeId,
                'amount'          => $c->amount,
            ]);
        }
    }

    /** @return array<int, string> active expense rows of a FOB/CIF list, id => name */
    private function activeExpenseRows(string $costingType): array
    {
        return CostingExpenseType::active()->forType($costingType)->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id) => [(int) $id => (string) $name])
            ->all();
    }

    /**
     * Bill lines already in another live costing.
     *
     * @param  list<int> $sourceIds
     * @param  bool      $lock      true inside a save transaction
     * @return array<int, string> source_id => that costing's document no
     */
    private function pulledElsewhere(array $sourceIds, ?int $costingId, bool $lock = false): array
    {
        if ($sourceIds === []) {
            return [];
        }

        // On save the rows (and the index gap where they would go) are locked,
        // so two costings saved at once cannot both take the same bill.
        if ($lock) {
            CostingBillCharge::query()
                ->where('source_type', CostingBillCharge::SOURCE_BILL_LINE)
                ->whereIn('source_id', $sourceIds)
                ->lockForUpdate()
                ->pluck('id');
        }

        return CostingBillCharge::query()
            ->where('source_type', CostingBillCharge::SOURCE_BILL_LINE)
            ->whereIn('source_id', $sourceIds)
            ->when($costingId !== null, fn ($q) => $q->where('costing_id', '!=', $costingId))
            ->whereHas('costing')
            ->with('costing:id,document_no')
            ->get()
            ->mapWithKeys(fn (CostingBillCharge $c) => [(int) $c->source_id => (string) $c->costing?->document_no])
            ->all();
    }
}
