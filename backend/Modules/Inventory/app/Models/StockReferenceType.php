<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class StockReferenceType extends Model
{
    /**
     * Code identity for stock ledger writers. Every feature that inserts
     * inv_stock_transactions rows must set reference_type from these constants —
     * the table rows carry the display labels, the constants carry the identity.
     */
    public const CODE_OPENING_STOCK    = 'opening_stock';
    public const CODE_GRN              = 'grn';
    public const CODE_CUSTOMER_RETURN  = 'customer_return';
    public const CODE_INVOICE          = 'invoice';
    public const CODE_SUPPLIER_RETURN  = 'supplier_return';
    public const CODE_STOCK_ADJUSTMENT = 'stock_adjustment';
    public const CODE_STOCK_TRANSFER   = 'stock_transfer';
    public const CODE_SALES_DELIVERY   = 'sales_delivery';

    protected $table = 'inv_stock_reference_types';

    protected $fillable = [
        'code',
        'label',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active'  => 'boolean',
    ];
}
