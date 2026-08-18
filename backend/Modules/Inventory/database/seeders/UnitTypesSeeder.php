<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UnitTypesSeeder extends Seeder
{
    public function run(): void
    {
        $catId = fn(string $name) => DB::table('inv_unit_categories')->where('name', $name)->value('id');

        $length  = $catId('Length');
        $weight  = $catId('Weight');
        $area    = $catId('Area');
        $piece   = $catId('Piece');
        $volume  = $catId('Volume');

        $units = [
            // Length
            ['unit_category_id' => $length, 'name' => 'Meter',      'symbol' => 'm',    'unit_position' => 'suffix'],
            ['unit_category_id' => $length, 'name' => 'Yard',       'symbol' => 'yd',   'unit_position' => 'suffix'],
            ['unit_category_id' => $length, 'name' => 'Centimeter', 'symbol' => 'cm',   'unit_position' => 'suffix'],
            ['unit_category_id' => $length, 'name' => 'Inch',       'symbol' => 'in',   'unit_position' => 'suffix'],
            ['unit_category_id' => $length, 'name' => 'Foot',       'symbol' => 'ft',   'unit_position' => 'suffix'],

            // Weight
            ['unit_category_id' => $weight, 'name' => 'Kilogram',   'symbol' => 'kg',   'unit_position' => 'suffix'],
            ['unit_category_id' => $weight, 'name' => 'Gram',       'symbol' => 'g',    'unit_position' => 'suffix'],
            ['unit_category_id' => $weight, 'name' => 'Pound',      'symbol' => 'lb',   'unit_position' => 'suffix'],
            ['unit_category_id' => $weight, 'name' => 'Ton',        'symbol' => 't',    'unit_position' => 'suffix'],

            // Area
            ['unit_category_id' => $area,   'name' => 'Square Meter',  'symbol' => 'm²',  'unit_position' => 'suffix'],
            ['unit_category_id' => $area,   'name' => 'Square Yard',   'symbol' => 'yd²', 'unit_position' => 'suffix'],
            ['unit_category_id' => $area,   'name' => 'Square Foot',   'symbol' => 'ft²', 'unit_position' => 'suffix'],

            // Piece
            ['unit_category_id' => $piece,  'name' => 'Piece',     'symbol' => 'pcs',  'unit_position' => 'suffix'],
            ['unit_category_id' => $piece,  'name' => 'Roll',      'symbol' => 'roll', 'unit_position' => 'suffix'],
            ['unit_category_id' => $piece,  'name' => 'Bolt',      'symbol' => 'bolt', 'unit_position' => 'suffix'],
            ['unit_category_id' => $piece,  'name' => 'Dozen',     'symbol' => 'doz',  'unit_position' => 'suffix'],
            ['unit_category_id' => $piece,  'name' => 'Box',       'symbol' => 'box',  'unit_position' => 'suffix'],
            ['unit_category_id' => $piece,  'name' => 'Bundle',    'symbol' => 'bnd',  'unit_position' => 'suffix'],
            ['unit_category_id' => $piece,  'name' => 'Cone',      'symbol' => 'cone', 'unit_position' => 'suffix'],

            // Volume
            ['unit_category_id' => $volume, 'name' => 'Litre',     'symbol' => 'L',    'unit_position' => 'suffix'],
            ['unit_category_id' => $volume, 'name' => 'Millilitre','symbol' => 'mL',   'unit_position' => 'suffix'],
            ['unit_category_id' => $volume, 'name' => 'Gallon',    'symbol' => 'gal',  'unit_position' => 'suffix'],
        ];

        foreach ($units as $unit) {
            DB::table('inv_unit_types')->updateOrInsert(
                ['unit_category_id' => $unit['unit_category_id'], 'symbol' => $unit['symbol']],
                array_merge($unit, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }

        // Set base unit for each category (first unit seeded per category)
        $basePairs = [
            'Length' => 'm',
            'Weight' => 'kg',
            'Area'   => 'm²',
            'Piece'  => 'pcs',
            'Volume' => 'L',
        ];

        foreach ($basePairs as $catName => $symbol) {
            $catId  = DB::table('inv_unit_categories')->where('name', $catName)->value('id');
            $unitId = DB::table('inv_unit_types')->where('unit_category_id', $catId)->where('symbol', $symbol)->value('id');
            if ($catId && $unitId) {
                DB::table('inv_unit_categories')->where('id', $catId)->update(['base_unit_type_id' => $unitId]);
            }
        }
    }
}
