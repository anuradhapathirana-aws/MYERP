<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductsSeeder extends Seeder
{
    public function run(): void
    {
        $catId  = fn(string $name) => DB::table('inv_categories')->where('category_name', $name)->value('id');
        $locId  = fn(string $code) => DB::table('inv_locations')->where('location_code', $code)->value('id');
        $supId  = fn(string $code) => DB::table('inv_supplier_masters')->where('supplier_code', $code)->value('id');
        $chanId = fn(string $name) => DB::table('inv_sales_channels')->where('sales_channel_name', $name)->value('id');
        $storeId = fn(string $code) => DB::table('inv_stores')->where('store_code', $code)->value('id');

        $factoryLocId   = $locId('LOC-FAC-001');
        $warehouseLocId = $locId('LOC-WH-001');
        $showroomLocId  = $locId('LOC-SHW-001');

        $products = [
            // ── Woven – Cotton ───────────────────────────────────────────────
            [
                'product_code'    => 'PRD-WOV-COT-001',
                'name'            => '100% Cotton Plain Weave Poplin',
                'display_name'    => 'Cotton Poplin Fabric',
                'product_type'    => 'Fabric',
                'description'     => '60×60 / 90×88 cotton poplin, 44" wide, reactive dyed, Sanforized finish',
                'category_id'     => $catId('Cotton Woven'),
                'location_id'     => $warehouseLocId,
                'reorder_level'   => 500.0000,
                'reorder_qty'     => 2000.0000,
                'reorder_period'  => 14,
                'tracking_type'   => null,
                'is_batch'        => false,
                'is_serial'       => false,
                'suppliers'       => ['SUP-0001', 'SUP-0002'],
                'sales_channels'  => [
                    ['channel' => 'Bulk Wholesale',  'cost_price' => 185.00, 'margin' => 20.00, 'selling_price' => 222.00],
                    ['channel' => 'Walk-In Retail',  'cost_price' => 185.00, 'margin' => 40.00, 'selling_price' => 259.00],
                    ['channel' => 'Dealer Network',  'cost_price' => 185.00, 'margin' => 30.00, 'selling_price' => 240.50],
                ],
                'stores' => [
                    ['store_code' => 'STR-FG-001',  'loc_code' => 'LOC-WH-001',  'stock' => 3500.0000],
                    ['store_code' => 'STR-SHW-001', 'loc_code' => 'LOC-SHW-001', 'stock' => 80.0000],
                ],
            ],
            [
                'product_code'    => 'PRD-WOV-COT-002',
                'name'            => 'Cotton Twill Fabric 3/1',
                'display_name'    => 'Cotton Twill',
                'product_type'    => 'Fabric',
                'description'     => 'Medium-weight 3/1 twill cotton, 58" wide, mercerized, 200 TC',
                'category_id'     => $catId('Cotton Woven'),
                'location_id'     => $warehouseLocId,
                'reorder_level'   => 300.0000,
                'reorder_qty'     => 1500.0000,
                'reorder_period'  => 14,
                'suppliers'       => ['SUP-0002'],
                'sales_channels'  => [
                    ['channel' => 'Bulk Wholesale',  'cost_price' => 210.00, 'margin' => 20.00, 'selling_price' => 252.00],
                    ['channel' => 'Walk-In Retail',  'cost_price' => 210.00, 'margin' => 38.00, 'selling_price' => 289.80],
                ],
                'stores' => [
                    ['store_code' => 'STR-FG-001', 'loc_code' => 'LOC-WH-001', 'stock' => 2200.0000],
                ],
            ],
            [
                'product_code'    => 'PRD-WOV-COT-003',
                'name'            => 'Cotton Canvas Fabric 12 OZ',
                'display_name'    => 'Canvas Fabric',
                'product_type'    => 'Fabric',
                'description'     => 'Heavy-duty 12 oz cotton canvas, 60" wide, natural/ecru',
                'category_id'     => $catId('Cotton Woven'),
                'location_id'     => $warehouseLocId,
                'reorder_level'   => 200.0000,
                'reorder_qty'     => 1000.0000,
                'reorder_period'  => 21,
                'suppliers'       => ['SUP-0002'],
                'sales_channels'  => [
                    ['channel' => 'Bulk Wholesale', 'cost_price' => 320.00, 'margin' => 18.00, 'selling_price' => 377.60],
                    ['channel' => 'Walk-In Retail', 'cost_price' => 320.00, 'margin' => 35.00, 'selling_price' => 432.00],
                ],
                'stores' => [
                    ['store_code' => 'STR-FG-001', 'loc_code' => 'LOC-WH-001', 'stock' => 800.0000],
                ],
            ],
            // ── Woven – Polyester ─────────────────────────────────────────────
            [
                'product_code'    => 'PRD-WOV-PES-001',
                'name'            => 'Polyester Satin Fabric 75D',
                'display_name'    => 'Polyester Satin',
                'product_type'    => 'Fabric',
                'description'     => '100% Polyester 75D×75D satin weave, 58" wide, disperse dyed',
                'category_id'     => $catId('Polyester Woven'),
                'location_id'     => $warehouseLocId,
                'reorder_level'   => 400.0000,
                'reorder_qty'     => 2000.0000,
                'reorder_period'  => 10,
                'suppliers'       => ['SUP-0002'],
                'sales_channels'  => [
                    ['channel' => 'Bulk Wholesale',  'cost_price' => 145.00, 'margin' => 22.00, 'selling_price' => 176.90],
                    ['channel' => 'Walk-In Retail',  'cost_price' => 145.00, 'margin' => 45.00, 'selling_price' => 210.25],
                    ['channel' => 'Online Store',    'cost_price' => 145.00, 'margin' => 38.00, 'selling_price' => 200.10],
                ],
                'stores' => [
                    ['store_code' => 'STR-FG-001',  'loc_code' => 'LOC-WH-001',  'stock' => 5000.0000],
                    ['store_code' => 'STR-SHW-001', 'loc_code' => 'LOC-SHW-001', 'stock' => 150.0000],
                ],
            ],
            [
                'product_code'    => 'PRD-WOV-PES-002',
                'name'            => 'Poly-Cotton Ripstop 65/35',
                'display_name'    => 'Ripstop Fabric P/C',
                'product_type'    => 'Fabric',
                'description'     => '65% Polyester / 35% Cotton ripstop, 60" wide, water repellent finish',
                'category_id'     => $catId('Blended Woven'),
                'location_id'     => $warehouseLocId,
                'reorder_level'   => 300.0000,
                'reorder_qty'     => 1500.0000,
                'reorder_period'  => 14,
                'suppliers'       => ['SUP-0002'],
                'sales_channels'  => [
                    ['channel' => 'Bulk Wholesale',  'cost_price' => 265.00, 'margin' => 20.00, 'selling_price' => 318.00],
                    ['channel' => 'Export Channel',  'cost_price' => 265.00, 'margin' => 15.00, 'selling_price' => 304.75],
                ],
                'stores' => [
                    ['store_code' => 'STR-FG-001', 'loc_code' => 'LOC-WH-001', 'stock' => 1800.0000],
                ],
            ],
            // ── Knit ─────────────────────────────────────────────────────────
            [
                'product_code'    => 'PRD-KNT-SJ-001',
                'name'            => 'Single Jersey 160 GSM 100% Cotton',
                'display_name'    => 'Cotton Single Jersey',
                'product_type'    => 'Fabric',
                'description'     => '100% Combed cotton single jersey, 160 GSM, compacted, 62/64" open width',
                'category_id'     => $catId('Single Jersey'),
                'location_id'     => $warehouseLocId,
                'reorder_level'   => 500.0000,
                'reorder_qty'     => 3000.0000,
                'reorder_period'  => 10,
                'suppliers'       => ['SUP-0001', 'SUP-0003'],
                'sales_channels'  => [
                    ['channel' => 'Bulk Wholesale',  'cost_price' => 195.00, 'margin' => 22.00, 'selling_price' => 237.90],
                    ['channel' => 'Export Channel',  'cost_price' => 195.00, 'margin' => 18.00, 'selling_price' => 230.10],
                    ['channel' => 'Dealer Network',  'cost_price' => 195.00, 'margin' => 28.00, 'selling_price' => 249.60],
                ],
                'stores' => [
                    ['store_code' => 'STR-FG-001', 'loc_code' => 'LOC-WH-001', 'stock' => 8000.0000],
                ],
            ],
            [
                'product_code'    => 'PRD-KNT-SJ-002',
                'name'            => 'Single Jersey 180 GSM CVC 60/40',
                'display_name'    => 'CVC Single Jersey 180G',
                'product_type'    => 'Fabric',
                'description'     => 'Chief Value Cotton 60% Cotton 40% Polyester, single jersey, 180 GSM',
                'category_id'     => $catId('Single Jersey'),
                'location_id'     => $warehouseLocId,
                'reorder_level'   => 400.0000,
                'reorder_qty'     => 2000.0000,
                'reorder_period'  => 10,
                'suppliers'       => ['SUP-0003'],
                'sales_channels'  => [
                    ['channel' => 'Bulk Wholesale',  'cost_price' => 165.00, 'margin' => 22.00, 'selling_price' => 201.30],
                    ['channel' => 'Export Channel',  'cost_price' => 165.00, 'margin' => 17.00, 'selling_price' => 193.05],
                ],
                'stores' => [
                    ['store_code' => 'STR-FG-001', 'loc_code' => 'LOC-WH-001', 'stock' => 6500.0000],
                ],
            ],
            [
                'product_code'    => 'PRD-KNT-FL-001',
                'name'            => 'Polar Fleece 300 GSM Anti-Pilling',
                'display_name'    => 'Polar Fleece 300G AP',
                'product_type'    => 'Fabric',
                'description'     => '100% Polyester anti-pilling polar fleece, 300 GSM, brushed & peached',
                'category_id'     => $catId('Fleece Knit'),
                'location_id'     => $warehouseLocId,
                'reorder_level'   => 300.0000,
                'reorder_qty'     => 1500.0000,
                'reorder_period'  => 21,
                'suppliers'       => ['SUP-0002'],
                'sales_channels'  => [
                    ['channel' => 'Bulk Wholesale',  'cost_price' => 280.00, 'margin' => 20.00, 'selling_price' => 336.00],
                    ['channel' => 'Export Channel',  'cost_price' => 280.00, 'margin' => 16.00, 'selling_price' => 324.80],
                ],
                'stores' => [
                    ['store_code' => 'STR-FG-001', 'loc_code' => 'LOC-WH-001', 'stock' => 2500.0000],
                ],
            ],
            [
                'product_code'    => 'PRD-KNT-INT-001',
                'name'            => 'Interlock Knit 200 GSM Cotton',
                'display_name'    => 'Cotton Interlock 200G',
                'product_type'    => 'Fabric',
                'description'     => '100% Cotton interlock, 200 GSM, softened finish',
                'category_id'     => $catId('Interlock Knit'),
                'location_id'     => $warehouseLocId,
                'reorder_level'   => 250.0000,
                'reorder_qty'     => 1200.0000,
                'reorder_period'  => 14,
                'suppliers'       => ['SUP-0003', 'SUP-0004'],
                'sales_channels'  => [
                    ['channel' => 'Bulk Wholesale',  'cost_price' => 220.00, 'margin' => 22.00, 'selling_price' => 268.40],
                    ['channel' => 'Walk-In Retail',  'cost_price' => 220.00, 'margin' => 40.00, 'selling_price' => 308.00],
                ],
                'stores' => [
                    ['store_code' => 'STR-FG-001',  'loc_code' => 'LOC-WH-001',  'stock' => 3000.0000],
                    ['store_code' => 'STR-SHW-001', 'loc_code' => 'LOC-SHW-001', 'stock' => 60.0000],
                ],
            ],
            // ── Yarn ─────────────────────────────────────────────────────────
            [
                'product_code'    => 'PRD-YARN-COT-001',
                'name'            => 'Combed Cotton Yarn 30/1 Ne',
                'display_name'    => 'Cotton Yarn 30/1',
                'product_type'    => 'Raw Material',
                'description'     => '100% combed cotton ring-spun yarn, 30/1 Ne, Z twist, single ply, waxed',
                'category_id'     => $catId('Cotton Yarn'),
                'location_id'     => $factoryLocId,
                'reorder_level'   => 2000.0000,
                'reorder_qty'     => 10000.0000,
                'reorder_period'  => 21,
                'suppliers'       => ['SUP-0003', 'SUP-0004'],
                'sales_channels'  => [],
                'stores' => [
                    ['store_code' => 'STR-RM-001', 'loc_code' => 'LOC-FAC-001', 'stock' => 15000.0000],
                ],
            ],
            [
                'product_code'    => 'PRD-YARN-PES-001',
                'name'            => 'Polyester Textured Yarn 150D/48F',
                'display_name'    => 'Polyester DTY 150D',
                'product_type'    => 'Raw Material',
                'description'     => 'Polyester draw-texturised yarn 150D/48F, S twist, semi-dull',
                'category_id'     => $catId('Polyester Yarn'),
                'location_id'     => $factoryLocId,
                'reorder_level'   => 1000.0000,
                'reorder_qty'     => 5000.0000,
                'reorder_period'  => 14,
                'suppliers'       => ['SUP-0002'],
                'sales_channels'  => [],
                'stores' => [
                    ['store_code' => 'STR-RM-001', 'loc_code' => 'LOC-FAC-001', 'stock' => 8000.0000],
                ],
            ],
            // ── Dyes & Chemicals ─────────────────────────────────────────────
            [
                'product_code'    => 'PRD-DYE-RX-001',
                'name'            => 'Remazol Red RGB Reactive Dye',
                'display_name'    => 'Remazol Red RGB',
                'product_type'    => 'Chemical',
                'description'     => 'Huntsman Remazol Red RGB, powder reactive dye for cotton',
                'category_id'     => $catId('Reactive Dyes'),
                'location_id'     => $factoryLocId,
                'reorder_level'   => 50.0000,
                'reorder_qty'     => 200.0000,
                'reorder_period'  => 30,
                'suppliers'       => ['SUP-0005'],
                'sales_channels'  => [],
                'stores' => [
                    ['store_code' => 'STR-CHM-001', 'loc_code' => 'LOC-FAC-001', 'stock' => 350.0000],
                ],
            ],
            [
                'product_code'    => 'PRD-DYE-RX-002',
                'name'            => 'Remazol Navy RGB Reactive Dye',
                'display_name'    => 'Remazol Navy RGB',
                'product_type'    => 'Chemical',
                'description'     => 'Huntsman Remazol Navy RGB, powder reactive dye for cotton',
                'category_id'     => $catId('Reactive Dyes'),
                'location_id'     => $factoryLocId,
                'reorder_level'   => 50.0000,
                'reorder_qty'     => 200.0000,
                'reorder_period'  => 30,
                'suppliers'       => ['SUP-0005', 'SUP-0006'],
                'sales_channels'  => [],
                'stores' => [
                    ['store_code' => 'STR-CHM-001', 'loc_code' => 'LOC-FAC-001', 'stock' => 420.0000],
                ],
            ],
            [
                'product_code'    => 'PRD-CHEM-AUX-001',
                'name'            => 'Soda Ash Light (Sodium Carbonate)',
                'display_name'    => 'Soda Ash Light',
                'product_type'    => 'Chemical',
                'description'     => 'Soda ash light, 98% purity, 50 kg bags, used as dye fixative',
                'category_id'     => $catId('Auxiliary Chemicals'),
                'location_id'     => $factoryLocId,
                'reorder_level'   => 500.0000,
                'reorder_qty'     => 2000.0000,
                'reorder_period'  => 30,
                'suppliers'       => ['SUP-0005', 'SUP-0006'],
                'sales_channels'  => [],
                'stores' => [
                    ['store_code' => 'STR-CHM-001', 'loc_code' => 'LOC-FAC-001', 'stock' => 3000.0000],
                ],
            ],
            // ── Packing Materials ─────────────────────────────────────────────
            [
                'product_code'    => 'PRD-PACK-PB-001',
                'name'            => 'LDPE Polybag 60"×80" 60 Micron',
                'display_name'    => 'Polybag 60"×80"',
                'product_type'    => 'Packing',
                'description'     => 'Low-density polyethylene polybag for fabric rolls, 60"×80", 60 micron',
                'category_id'     => $catId('Polybags'),
                'location_id'     => $warehouseLocId,
                'reorder_level'   => 500.0000,
                'reorder_qty'     => 5000.0000,
                'reorder_period'  => 30,
                'suppliers'       => ['SUP-0007'],
                'sales_channels'  => [],
                'stores' => [
                    ['store_code' => 'STR-PKG-001', 'loc_code' => 'LOC-WH-001', 'stock' => 12000.0000],
                ],
            ],
            [
                'product_code'    => 'PRD-PACK-CTN-001',
                'name'            => 'Export Carton 24"×18"×12" 5-Ply',
                'display_name'    => 'Export Carton 5-Ply',
                'product_type'    => 'Packing',
                'description'     => '5-ply corrugated export carton, 24"×18"×12"',
                'category_id'     => $catId('Cartons & Boxes'),
                'location_id'     => $warehouseLocId,
                'reorder_level'   => 200.0000,
                'reorder_qty'     => 1000.0000,
                'reorder_period'  => 30,
                'suppliers'       => ['SUP-0007'],
                'sales_channels'  => [],
                'stores' => [
                    ['store_code' => 'STR-PKG-001', 'loc_code' => 'LOC-WH-001', 'stock' => 3000.0000],
                ],
            ],
        ];

        foreach ($products as $product) {
            $supplierCodes  = $product['suppliers'];
            $channelsData   = $product['sales_channels'];
            $storesData     = $product['stores'];

            unset($product['suppliers'], $product['sales_channels'], $product['stores']);

            // Upsert product
            DB::table('inv_products')->updateOrInsert(
                ['product_code' => $product['product_code']],
                array_merge($product, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );

            $productId = DB::table('inv_products')->where('product_code', $product['product_code'])->value('id');

            // Supplier links
            foreach ($supplierCodes as $code) {
                $sid = $supId($code);
                if ($sid && $productId) {
                    $exists = DB::table('inv_product_supplier')
                        ->where('product_id', $productId)
                        ->where('supplier_master_id', $sid)
                        ->exists();
                    if (! $exists) {
                        DB::table('inv_product_supplier')->insert([
                            'product_id'         => $productId,
                            'supplier_master_id' => $sid,
                            'created_at'         => now(),
                            'updated_at'         => now(),
                        ]);
                    }
                }
            }

            // Sales channel pricing
            foreach ($channelsData as $ch) {
                $cid = $chanId($ch['channel']);
                if ($cid && $productId) {
                    DB::table('inv_product_sales_channels')->updateOrInsert(
                        ['product_id' => $productId, 'sales_channel_id' => $cid],
                        [
                            'product_id'      => $productId,
                            'sales_channel_id'=> $cid,
                            'cost_price'      => $ch['cost_price'],
                            'margin'          => $ch['margin'],
                            'margin_type'     => 'percentage',
                            'selling_price'   => $ch['selling_price'],
                            'created_at'      => now(),
                            'updated_at'      => now(),
                        ]
                    );
                }
            }

            // Product-location-store stock
            foreach ($storesData as $storeEntry) {
                $sid    = $storeId($storeEntry['store_code']);
                $lid    = $locId($storeEntry['loc_code']);
                if ($sid && $lid && $productId) {
                    DB::table('inv_product_location_stores')->updateOrInsert(
                        ['product_id' => $productId, 'store_id' => $sid, 'location_id' => $lid],
                        [
                            'product_id'    => $productId,
                            'location_id'   => $lid,
                            'store_id'      => $sid,
                            'current_stock' => $storeEntry['stock'],
                            'created_at'    => now(),
                            'updated_at'    => now(),
                        ]
                    );
                }
            }
        }
    }
}
