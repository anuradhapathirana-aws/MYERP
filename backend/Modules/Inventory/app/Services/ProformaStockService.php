<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Modules\Inventory\Models\GrnItemPiece;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProformaInvoiceItem;
use Modules\Inventory\Support\Quantity;

/**
 * What a proforma line could ship today — informational only; a proforma never
 * reserves stock (reservation stays with the sales order the proforma is
 * recalled into).
 *
 *   roll-tracked     — unreserved in-stock rolls (status in_stock), by colour
 *   quantity-tracked — the colour balance on the stock ledger
 *
 * Measured in the stocking UOM and restated in the line's UOM.
 */
class ProformaStockService
{
    public function __construct(
        private readonly ColourStockService $colourStock,
        private readonly UnitConversionService $units,
        private readonly VariantStockService $variantStock,
    ) {
    }

    /**
     * @return array{available_qty: float, stock_status: string}
     */
    public function check(int $productId, ?int $attributeId, ?int $unitId, float $quantity, ?int $variantId = null): array
    {
        $available = $this->availableInLineUnit($productId, $attributeId, $unitId, $variantId);

        $status = match (true) {
            $available <= 0.0                         => ProformaInvoiceItem::STOCK_NONE,
            $available + 0.0001 >= max($quantity, 0.0) => ProformaInvoiceItem::STOCK_IN,
            default                                   => ProformaInvoiceItem::STOCK_PARTIAL,
        };

        return ['available_qty' => round($available, 4), 'stock_status' => $status];
    }

    private function availableInLineUnit(int $productId, ?int $attributeId, ?int $unitId, ?int $variantId = null): float
    {
        $product = Product::find($productId);
        if ($product === null) {
            return 0.0;
        }

        $base = $product->isRollTracked()
            ? $this->rollStock($productId, $attributeId)
            : ($variantId !== null
                ? $this->variantStock->available($productId, $variantId)
                : $this->quantityStock($productId, $attributeId));

        $base = max(0.0, $base);

        $baseUnitId = $product->base_unit_type_id !== null ? (int) $product->base_unit_type_id : null;
        if ($unitId === null || $baseUnitId === null || $unitId === $baseUnitId) {
            return $base;
        }

        // factor = stocking units per ONE line unit (1 yd = 0.9144 m)
        $factor = $this->units->tryFactor($unitId, $baseUnitId);

        return ($factor !== null && $factor > 0.0) ? $base / $factor : $base;
    }

    /** Unreserved rolls — what a new SO could still pick. */
    private function rollStock(int $productId, ?int $attributeId): float
    {
        return (float) GrnItemPiece::query()
            ->where('product_id', $productId)
            ->where('status', GrnItemPiece::STATUS_IN_STOCK)
            ->where('weight', '>=', Quantity::minSellable())
            ->when($attributeId !== null, fn ($q) => $q->whereHas(
                'grnItem',
                fn ($g) => $g->where('attribute_id', $attributeId),
            ))
            ->sum('weight');
    }

    /** Ledger balance of the colour (or of the whole product when no colour is chosen). */
    private function quantityStock(int $productId, ?int $attributeId): float
    {
        if ($attributeId !== null) {
            return $this->colourStock->available($productId, $attributeId);
        }

        return (float) collect($this->colourStock->balances($productId))->sum('quantity');
    }
}
