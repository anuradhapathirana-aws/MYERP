<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategoriesSeeder extends Seeder
{
    public function run(): void
    {
        $industryId = DB::table('inv_industries')->where('name', 'Textile & Fabrics')->value('id');
        $companyId  = DB::table('inv_companies')->where('company_name', 'SilkRoute Fabrics (Pvt) Ltd')->value('id');

        // ── Parent categories ──────────────────────────────────────────────
        $parents = [
            ['category_name' => 'Woven Fabrics',        'reference_name' => 'WOVEN',   'product_service_type' => 'product'],
            ['category_name' => 'Knit Fabrics',         'reference_name' => 'KNIT',    'product_service_type' => 'product'],
            ['category_name' => 'Non-Woven Fabrics',    'reference_name' => 'NONWOV',  'product_service_type' => 'product'],
            ['category_name' => 'Yarn & Thread',        'reference_name' => 'YARN',    'product_service_type' => 'product'],
            ['category_name' => 'Dyes & Chemicals',     'reference_name' => 'DYECHEM', 'product_service_type' => 'product'],
            ['category_name' => 'Packing Materials',    'reference_name' => 'PACK',    'product_service_type' => 'product'],
            ['category_name' => 'Spare Parts',          'reference_name' => 'SPARE',   'product_service_type' => 'product'],
            ['category_name' => 'Processing Services',  'reference_name' => 'SVC',     'product_service_type' => 'service'],
        ];

        foreach ($parents as &$parent) {
            DB::table('inv_categories')->updateOrInsert(
                ['category_name' => $parent['category_name'], 'parent_category_id' => null],
                array_merge($parent, [
                    'industry_id'        => $industryId,
                    'company_id'         => $companyId,
                    'parent_category_id' => null,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ])
            );
            $parent['id'] = DB::table('inv_categories')->where('category_name', $parent['category_name'])->value('id');
        }
        unset($parent);

        $pid = fn(string $name) => collect($parents)->firstWhere('category_name', $name)['id'];

        // ── Sub-categories ─────────────────────────────────────────────────
        $children = [
            // Woven
            ['parent' => 'Woven Fabrics',     'category_name' => 'Cotton Woven',          'reference_name' => 'WOVEN-COT'],
            ['parent' => 'Woven Fabrics',     'category_name' => 'Polyester Woven',        'reference_name' => 'WOVEN-PES'],
            ['parent' => 'Woven Fabrics',     'category_name' => 'Silk Woven',             'reference_name' => 'WOVEN-SLK'],
            ['parent' => 'Woven Fabrics',     'category_name' => 'Linen Woven',            'reference_name' => 'WOVEN-LIN'],
            ['parent' => 'Woven Fabrics',     'category_name' => 'Blended Woven',          'reference_name' => 'WOVEN-BLD'],
            // Knit
            ['parent' => 'Knit Fabrics',      'category_name' => 'Single Jersey',          'reference_name' => 'KNIT-SJ'],
            ['parent' => 'Knit Fabrics',      'category_name' => 'Double Jersey',          'reference_name' => 'KNIT-DJ'],
            ['parent' => 'Knit Fabrics',      'category_name' => 'Fleece Knit',            'reference_name' => 'KNIT-FL'],
            ['parent' => 'Knit Fabrics',      'category_name' => 'Rib Knit',               'reference_name' => 'KNIT-RIB'],
            ['parent' => 'Knit Fabrics',      'category_name' => 'Interlock Knit',         'reference_name' => 'KNIT-INT'],
            // Non-Woven
            ['parent' => 'Non-Woven Fabrics', 'category_name' => 'Spunbond Non-Woven',    'reference_name' => 'NW-SPB'],
            ['parent' => 'Non-Woven Fabrics', 'category_name' => 'Needle-Punch Non-Woven','reference_name' => 'NW-NP'],
            // Yarn
            ['parent' => 'Yarn & Thread',     'category_name' => 'Cotton Yarn',            'reference_name' => 'YARN-COT'],
            ['parent' => 'Yarn & Thread',     'category_name' => 'Polyester Yarn',         'reference_name' => 'YARN-PES'],
            ['parent' => 'Yarn & Thread',     'category_name' => 'Blended Yarn',           'reference_name' => 'YARN-BLD'],
            ['parent' => 'Yarn & Thread',     'category_name' => 'Sewing Thread',          'reference_name' => 'THREAD'],
            // Dyes
            ['parent' => 'Dyes & Chemicals',  'category_name' => 'Reactive Dyes',         'reference_name' => 'DYE-RX'],
            ['parent' => 'Dyes & Chemicals',  'category_name' => 'Disperse Dyes',         'reference_name' => 'DYE-DS'],
            ['parent' => 'Dyes & Chemicals',  'category_name' => 'Auxiliary Chemicals',   'reference_name' => 'CHEM-AUX'],
            // Packing
            ['parent' => 'Packing Materials', 'category_name' => 'Polybags',              'reference_name' => 'PACK-PB'],
            ['parent' => 'Packing Materials', 'category_name' => 'Cartons & Boxes',       'reference_name' => 'PACK-CTN'],
            ['parent' => 'Packing Materials', 'category_name' => 'Labels & Tags',         'reference_name' => 'PACK-LBL'],
            // Services
            ['parent' => 'Processing Services','category_name' => 'Dyeing Services',      'reference_name' => 'SVC-DYE'],
            ['parent' => 'Processing Services','category_name' => 'Printing Services',    'reference_name' => 'SVC-PRNT'],
            ['parent' => 'Processing Services','category_name' => 'Finishing Services',   'reference_name' => 'SVC-FIN'],
        ];

        foreach ($children as $child) {
            DB::table('inv_categories')->updateOrInsert(
                ['category_name' => $child['category_name']],
                [
                    'product_service_type' => 'product',
                    'industry_id'          => $industryId,
                    'company_id'           => $companyId,
                    'parent_category_id'   => $pid($child['parent']),
                    'category_name'        => $child['category_name'],
                    'reference_name'       => $child['reference_name'],
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]
            );
        }
    }
}
