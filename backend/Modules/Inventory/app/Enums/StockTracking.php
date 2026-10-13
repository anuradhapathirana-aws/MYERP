<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

/**
 * How a product's stock is physically counted.
 *
 * Roll     — every unit received becomes a QR-labelled piece (inv_grn_item_pieces)
 *            that sales orders allocate and delivery orders ship or cut.
 * Quantity — plain quantity per store/location (and colour); no pieces at all.
 *            Finished goods and any ordinary item.
 */
enum StockTracking: string
{
    case Quantity = 'quantity';
    case Roll     = 'roll';

    public function label(): string
    {
        return match ($this) {
            self::Quantity => 'Quantity',
            self::Roll     => 'Roll',
        };
    }
}
