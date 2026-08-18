<?php

declare(strict_types=1);

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Inventory\Models\StockReferenceType;

class StockReferenceTypesSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => StockReferenceType::CODE_OPENING_STOCK,    'label' => 'Opening Stock',    'sort_order' => 1],
            ['code' => StockReferenceType::CODE_GRN,              'label' => 'Goods Received Note (GRN)', 'sort_order' => 2],
            ['code' => StockReferenceType::CODE_CUSTOMER_RETURN,  'label' => 'Customer Return',  'sort_order' => 3],
            ['code' => StockReferenceType::CODE_INVOICE,          'label' => 'Invoice',          'sort_order' => 4],
            ['code' => StockReferenceType::CODE_SUPPLIER_RETURN,  'label' => 'Supplier Return',  'sort_order' => 5],
            ['code' => StockReferenceType::CODE_STOCK_ADJUSTMENT, 'label' => 'Stock Adjustment', 'sort_order' => 6],
            ['code' => StockReferenceType::CODE_STOCK_TRANSFER,   'label' => 'Stock Transfer',   'sort_order' => 7],
            ['code' => StockReferenceType::CODE_SALES_DELIVERY,   'label' => 'Sales Delivery',   'sort_order' => 8],
        ];

        foreach ($types as $type) {
            StockReferenceType::updateOrCreate(
                ['code' => $type['code']],
                ['label' => $type['label'], 'sort_order' => $type['sort_order'], 'is_active' => true],
            );
        }
    }
}
