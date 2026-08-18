<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UnitCategoriesSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Length',   'description' => 'Linear measurements for fabric rolls and cuts',    'is_default' => true],
            ['name' => 'Weight',   'description' => 'Mass measurements for raw materials and yarn',      'is_default' => false],
            ['name' => 'Area',     'description' => 'Surface area measurements',                         'is_default' => false],
            ['name' => 'Piece',    'description' => 'Discrete item counts',                              'is_default' => false],
            ['name' => 'Volume',   'description' => 'Liquid measurements for dyes and chemicals',        'is_default' => false],
            ['name' => 'Time',     'description' => 'Duration measurements for processing times',        'is_default' => false],
        ];

        foreach ($categories as $cat) {
            DB::table('inv_unit_categories')->updateOrInsert(
                ['name' => $cat['name']],
                array_merge($cat, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
