<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AttributeTypesSeeder extends Seeder
{
    public function run(): void
    {
        $catId = fn(string $name) => DB::table('inv_categories')->where('category_name', $name)->value('id');

        $wovenCotId  = $catId('Cotton Woven');
        $knitSJId    = $catId('Single Jersey');
        $yarnCotId   = $catId('Cotton Yarn');
        $wovenId     = $catId('Woven Fabrics');
        $knitId      = $catId('Knit Fabrics');

        $attributeTypes = [
            // Common fabric attributes (attached to parent categories)
            ['category_id' => $wovenId,    'product_service_type' => 'product', 'attribute_type_name' => 'Color',             'description' => 'Fabric colour shade'],
            ['category_id' => $wovenId,    'product_service_type' => 'product', 'attribute_type_name' => 'Width',             'description' => 'Fabric width (e.g. 44", 60")'],
            ['category_id' => $wovenId,    'product_service_type' => 'product', 'attribute_type_name' => 'Weave Pattern',     'description' => 'Weave construction type'],
            ['category_id' => $wovenId,    'product_service_type' => 'product', 'attribute_type_name' => 'Thread Count',      'description' => 'Threads per square inch'],
            ['category_id' => $wovenId,    'product_service_type' => 'product', 'attribute_type_name' => 'Finish',            'description' => 'Surface finish applied'],
            ['category_id' => $knitId,     'product_service_type' => 'product', 'attribute_type_name' => 'Color',             'description' => 'Fabric colour shade'],
            ['category_id' => $knitId,     'product_service_type' => 'product', 'attribute_type_name' => 'GSM',               'description' => 'Grams per square metre'],
            ['category_id' => $knitId,     'product_service_type' => 'product', 'attribute_type_name' => 'Fiber Content',     'description' => 'Fibre composition percentage'],
            ['category_id' => $knitId,     'product_service_type' => 'product', 'attribute_type_name' => 'Finish',            'description' => 'Surface finish applied'],
            // Yarn specific
            ['category_id' => $yarnCotId,  'product_service_type' => 'product', 'attribute_type_name' => 'Yarn Count',        'description' => 'Ne or Tex yarn count'],
            ['category_id' => $yarnCotId,  'product_service_type' => 'product', 'attribute_type_name' => 'Twist Direction',   'description' => 'S or Z twist'],
            ['category_id' => $yarnCotId,  'product_service_type' => 'product', 'attribute_type_name' => 'Ply',               'description' => 'Single or multi-ply'],
        ];

        foreach ($attributeTypes as $type) {
            DB::table('inv_attribute_types')->updateOrInsert(
                ['category_id' => $type['category_id'], 'attribute_type_name' => $type['attribute_type_name']],
                array_merge($type, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
