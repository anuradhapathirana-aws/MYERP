<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StoreTypesSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['store_type_name' => 'Raw Material Store',      'description' => 'Stores unprocessed inputs: grey fabric, yarn, raw cotton',          'is_active' => true],
            ['store_type_name' => 'Finished Goods Store',    'description' => 'Stores dyed, finished, and packed fabric ready for dispatch',        'is_active' => true],
            ['store_type_name' => 'Work-In-Progress Store',  'description' => 'Intermediate storage between production stages',                      'is_active' => true],
            ['store_type_name' => 'Chemical & Dye Store',    'description' => 'Stores dyes, chemicals, and auxiliary agents',                       'is_active' => true],
            ['store_type_name' => 'Packing Material Store',  'description' => 'Stores polybags, cartons, labels, and packing accessories',          'is_active' => true],
            ['store_type_name' => 'Spare Parts Store',       'description' => 'Machinery spare parts and maintenance supplies',                      'is_active' => true],
            ['store_type_name' => 'Rejected Goods Store',    'description' => 'Holds fabric that failed quality inspection pending review',          'is_active' => true],
            ['store_type_name' => 'Showroom',                'description' => 'Display area for finished fabric samples and customer orders',         'is_active' => true],
        ];

        foreach ($types as $type) {
            DB::table('inv_store_types')->updateOrInsert(
                ['store_type_name' => $type['store_type_name']],
                array_merge($type, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
