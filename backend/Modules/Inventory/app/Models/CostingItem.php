<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Per-product landed-cost breakdown line of a costing. Monetary values are
 * PER UNIT: unit_price (GRN purchase) + charge_portion = landed_unit_cost,
 * + margin_amount (±SSCL/±VAT when toggled) = selling_price.
 *
 * That build-up is denominated in the GRN's RECEIVING unit (unit_id) — the unit the
 * supplier quoted. The *_base columns restate the two figures that leave this table
 * (landed cost and selling price) per the product's stocking UOM, which is what the
 * price list and every sales document speak. conversion_factor is the GRN line's own
 * frozen factor, so a costing values a shipment exactly as the stock ledger did.
 */
class CostingItem extends Model
{
    protected $table = 'inv_costing_items';

    protected $fillable = [
        'costing_id',
        'grn_id',
        'grn_item_id',
        'product_id',
        'attribute_id',
        'unit_id',
        'quantity',
        'conversion_factor',
        'base_quantity',
        'base_unit_id',
        'unit_price',
        'charge_portion',
        'landed_unit_cost',
        'landed_unit_cost_base',
        'margin_pct',
        'margin_amount',
        'sscl_pct',
        'sscl_amount',
        'vat_pct',
        'vat_amount',
        'expense_total_base',
        'margin_amount_base',
        'sscl_amount_base',
        'vat_amount_base',
        'before_tax_price',
        'before_tax_price_base',
        'selling_price',
        'selling_price_base',
        'is_price_overridden',
    ];

    protected $casts = [
        'costing_id'            => 'integer',
        'grn_id'                => 'integer',
        'grn_item_id'           => 'integer',
        'product_id'            => 'integer',
        'attribute_id'          => 'integer',
        'unit_id'               => 'integer',
        'base_unit_id'          => 'integer',
        'quantity'              => 'decimal:4',
        'conversion_factor'     => 'decimal:10',
        'base_quantity'         => 'decimal:6',
        'unit_price'            => 'decimal:8',
        'charge_portion'        => 'decimal:8',
        'landed_unit_cost'      => 'decimal:8',
        'landed_unit_cost_base' => 'decimal:8',
        'margin_pct'            => 'decimal:4',
        'margin_amount'         => 'decimal:8',
        'sscl_pct'              => 'decimal:2',
        'sscl_amount'           => 'decimal:8',
        'vat_pct'               => 'decimal:2',
        'vat_amount'            => 'decimal:8',
        'expense_total_base'    => 'decimal:8',
        'margin_amount_base'    => 'decimal:8',
        'sscl_amount_base'      => 'decimal:8',
        'vat_amount_base'       => 'decimal:8',
        'before_tax_price'      => 'decimal:8',
        'before_tax_price_base' => 'decimal:8',
        'selling_price'         => 'decimal:8',
        'selling_price_base'    => 'decimal:8',
        'is_price_overridden'   => 'boolean',
    ];

    public function costing(): BelongsTo
    {
        return $this->belongsTo(Costing::class, 'costing_id');
    }

    public function grn(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNote::class, 'grn_id');
    }

    public function grnItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNoteItem::class, 'grn_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class, 'attribute_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitType::class, 'unit_id');
    }

    /** The product's stocking UOM at costing time — what *_base is denominated in. */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(UnitType::class, 'base_unit_id');
    }

    /** This line's typed expense amounts (per base unit, one row per expense type). */
    public function expenses(): HasMany
    {
        return $this->hasMany(CostingItemExpense::class, 'costing_item_id');
    }
}
