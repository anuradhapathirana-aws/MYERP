<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class IndustriesSeeder extends Seeder
{
    public function run(): void
    {
        $industries = [
            ['name' => 'Textile & Fabrics',        'description' => 'Spinning, weaving, knitting, and fabric manufacturing'],
            ['name' => 'Apparel & Garments',        'description' => 'Clothing design, cutting, sewing, and finishing'],
            ['name' => 'Fashion Retail',            'description' => 'Boutiques, department stores, and fashion retailers'],
            ['name' => 'Industrial Manufacturing',  'description' => 'Heavy machinery, industrial textiles, and technical fabrics'],
            ['name' => 'Home Furnishings',          'description' => 'Upholstery, curtains, bed linen, and soft furnishings'],
            ['name' => 'Yarn & Thread',             'description' => 'Yarn spinning, thread production, and raw fibre processing'],
        ];

        foreach ($industries as $industry) {
            DB::table('inv_industries')->updateOrInsert(
                ['name' => $industry['name']],
                array_merge($industry, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
