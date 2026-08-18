<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AttributesSeeder extends Seeder
{
    public function run(): void
    {
        $typeId = fn(string $typeName, string $catName) => DB::table('inv_attribute_types')
            ->where('attribute_type_name', $typeName)
            ->where('category_id', DB::table('inv_categories')->where('category_name', $catName)->value('id'))
            ->value('id');

        $colorWovenId      = $typeId('Color',          'Woven Fabrics');
        $widthId           = $typeId('Width',           'Woven Fabrics');
        $weavePatternId    = $typeId('Weave Pattern',   'Woven Fabrics');
        $threadCountId     = $typeId('Thread Count',    'Woven Fabrics');
        $finishWovenId     = $typeId('Finish',          'Woven Fabrics');
        $colorKnitId       = $typeId('Color',           'Knit Fabrics');
        $gsmId             = $typeId('GSM',             'Knit Fabrics');
        $fiberContentId    = $typeId('Fiber Content',   'Knit Fabrics');
        $finishKnitId      = $typeId('Finish',          'Knit Fabrics');
        $yarnCountId       = $typeId('Yarn Count',      'Cotton Yarn');
        $twistId           = $typeId('Twist Direction', 'Cotton Yarn');
        $plyId             = $typeId('Ply',             'Cotton Yarn');

        $attributes = [
            // Colors – Woven
            ['attribute_type_id' => $colorWovenId, 'attribute_name' => 'White'],
            ['attribute_type_id' => $colorWovenId, 'attribute_name' => 'Off White'],
            ['attribute_type_id' => $colorWovenId, 'attribute_name' => 'Ecru'],
            ['attribute_type_id' => $colorWovenId, 'attribute_name' => 'Black'],
            ['attribute_type_id' => $colorWovenId, 'attribute_name' => 'Navy Blue'],
            ['attribute_type_id' => $colorWovenId, 'attribute_name' => 'Sky Blue'],
            ['attribute_type_id' => $colorWovenId, 'attribute_name' => 'Royal Blue'],
            ['attribute_type_id' => $colorWovenId, 'attribute_name' => 'Red'],
            ['attribute_type_id' => $colorWovenId, 'attribute_name' => 'Maroon'],
            ['attribute_type_id' => $colorWovenId, 'attribute_name' => 'Olive Green'],
            ['attribute_type_id' => $colorWovenId, 'attribute_name' => 'Khaki'],
            ['attribute_type_id' => $colorWovenId, 'attribute_name' => 'Beige'],
            ['attribute_type_id' => $colorWovenId, 'attribute_name' => 'Grey'],
            ['attribute_type_id' => $colorWovenId, 'attribute_name' => 'Charcoal'],
            ['attribute_type_id' => $colorWovenId, 'attribute_name' => 'Yellow'],
            ['attribute_type_id' => $colorWovenId, 'attribute_name' => 'Orange'],
            ['attribute_type_id' => $colorWovenId, 'attribute_name' => 'Pink'],
            ['attribute_type_id' => $colorWovenId, 'attribute_name' => 'Purple'],
            ['attribute_type_id' => $colorWovenId, 'attribute_name' => 'Teal'],
            ['attribute_type_id' => $colorWovenId, 'attribute_name' => 'Printed'],

            // Width
            ['attribute_type_id' => $widthId, 'attribute_name' => '36 Inches'],
            ['attribute_type_id' => $widthId, 'attribute_name' => '44 Inches'],
            ['attribute_type_id' => $widthId, 'attribute_name' => '58 Inches'],
            ['attribute_type_id' => $widthId, 'attribute_name' => '60 Inches'],
            ['attribute_type_id' => $widthId, 'attribute_name' => '72 Inches'],

            // Weave Pattern
            ['attribute_type_id' => $weavePatternId, 'attribute_name' => 'Plain Weave'],
            ['attribute_type_id' => $weavePatternId, 'attribute_name' => 'Twill Weave'],
            ['attribute_type_id' => $weavePatternId, 'attribute_name' => 'Satin Weave'],
            ['attribute_type_id' => $weavePatternId, 'attribute_name' => 'Oxford Weave'],
            ['attribute_type_id' => $weavePatternId, 'attribute_name' => 'Dobby Weave'],
            ['attribute_type_id' => $weavePatternId, 'attribute_name' => 'Jacquard Weave'],
            ['attribute_type_id' => $weavePatternId, 'attribute_name' => 'Canvas Weave'],
            ['attribute_type_id' => $weavePatternId, 'attribute_name' => 'Ripstop Weave'],

            // Thread Count
            ['attribute_type_id' => $threadCountId, 'attribute_name' => '100 TC'],
            ['attribute_type_id' => $threadCountId, 'attribute_name' => '144 TC'],
            ['attribute_type_id' => $threadCountId, 'attribute_name' => '180 TC'],
            ['attribute_type_id' => $threadCountId, 'attribute_name' => '200 TC'],
            ['attribute_type_id' => $threadCountId, 'attribute_name' => '300 TC'],
            ['attribute_type_id' => $threadCountId, 'attribute_name' => '400 TC'],
            ['attribute_type_id' => $threadCountId, 'attribute_name' => '600 TC'],

            // Finish – Woven
            ['attribute_type_id' => $finishWovenId, 'attribute_name' => 'Mercerized'],
            ['attribute_type_id' => $finishWovenId, 'attribute_name' => 'Sanforized'],
            ['attribute_type_id' => $finishWovenId, 'attribute_name' => 'Calendered'],
            ['attribute_type_id' => $finishWovenId, 'attribute_name' => 'Anti-Wrinkle'],
            ['attribute_type_id' => $finishWovenId, 'attribute_name' => 'Water Repellent'],
            ['attribute_type_id' => $finishWovenId, 'attribute_name' => 'Flame Retardant'],
            ['attribute_type_id' => $finishWovenId, 'attribute_name' => 'Greige (Unfinished)'],

            // Colors – Knit
            ['attribute_type_id' => $colorKnitId, 'attribute_name' => 'White'],
            ['attribute_type_id' => $colorKnitId, 'attribute_name' => 'Black'],
            ['attribute_type_id' => $colorKnitId, 'attribute_name' => 'Navy Blue'],
            ['attribute_type_id' => $colorKnitId, 'attribute_name' => 'Grey Melange'],
            ['attribute_type_id' => $colorKnitId, 'attribute_name' => 'Charcoal Melange'],
            ['attribute_type_id' => $colorKnitId, 'attribute_name' => 'Red'],
            ['attribute_type_id' => $colorKnitId, 'attribute_name' => 'Royal Blue'],
            ['attribute_type_id' => $colorKnitId, 'attribute_name' => 'Green'],
            ['attribute_type_id' => $colorKnitId, 'attribute_name' => 'Yellow'],
            ['attribute_type_id' => $colorKnitId, 'attribute_name' => 'Printed'],

            // GSM – Knit
            ['attribute_type_id' => $gsmId, 'attribute_name' => '120 GSM'],
            ['attribute_type_id' => $gsmId, 'attribute_name' => '150 GSM'],
            ['attribute_type_id' => $gsmId, 'attribute_name' => '160 GSM'],
            ['attribute_type_id' => $gsmId, 'attribute_name' => '180 GSM'],
            ['attribute_type_id' => $gsmId, 'attribute_name' => '200 GSM'],
            ['attribute_type_id' => $gsmId, 'attribute_name' => '220 GSM'],
            ['attribute_type_id' => $gsmId, 'attribute_name' => '260 GSM'],
            ['attribute_type_id' => $gsmId, 'attribute_name' => '300 GSM'],
            ['attribute_type_id' => $gsmId, 'attribute_name' => '320 GSM'],
            ['attribute_type_id' => $gsmId, 'attribute_name' => '350 GSM'],

            // Fiber Content – Knit
            ['attribute_type_id' => $fiberContentId, 'attribute_name' => '100% Cotton'],
            ['attribute_type_id' => $fiberContentId, 'attribute_name' => '100% Polyester'],
            ['attribute_type_id' => $fiberContentId, 'attribute_name' => '65% Polyester / 35% Cotton'],
            ['attribute_type_id' => $fiberContentId, 'attribute_name' => '50% Cotton / 50% Polyester'],
            ['attribute_type_id' => $fiberContentId, 'attribute_name' => '95% Cotton / 5% Elastane'],
            ['attribute_type_id' => $fiberContentId, 'attribute_name' => '60% Cotton / 40% Modal'],
            ['attribute_type_id' => $fiberContentId, 'attribute_name' => '100% Viscose'],

            // Finish – Knit
            ['attribute_type_id' => $finishKnitId, 'attribute_name' => 'Compacted'],
            ['attribute_type_id' => $finishKnitId, 'attribute_name' => 'Brushed'],
            ['attribute_type_id' => $finishKnitId, 'attribute_name' => 'Peached'],
            ['attribute_type_id' => $finishKnitId, 'attribute_name' => 'Anti-Pilling'],
            ['attribute_type_id' => $finishKnitId, 'attribute_name' => 'Softened'],

            // Yarn Count
            ['attribute_type_id' => $yarnCountId, 'attribute_name' => '10/1 Ne'],
            ['attribute_type_id' => $yarnCountId, 'attribute_name' => '20/1 Ne'],
            ['attribute_type_id' => $yarnCountId, 'attribute_name' => '30/1 Ne'],
            ['attribute_type_id' => $yarnCountId, 'attribute_name' => '40/1 Ne'],
            ['attribute_type_id' => $yarnCountId, 'attribute_name' => '60/1 Ne'],

            // Twist Direction
            ['attribute_type_id' => $twistId, 'attribute_name' => 'S Twist'],
            ['attribute_type_id' => $twistId, 'attribute_name' => 'Z Twist'],

            // Ply
            ['attribute_type_id' => $plyId, 'attribute_name' => 'Single Ply (1/1)'],
            ['attribute_type_id' => $plyId, 'attribute_name' => 'Two Ply (2/1)'],
            ['attribute_type_id' => $plyId, 'attribute_name' => 'Three Ply (3/1)'],
        ];

        foreach ($attributes as $attr) {
            if (! $attr['attribute_type_id']) {
                continue;
            }
            DB::table('inv_attributes')->updateOrInsert(
                ['attribute_type_id' => $attr['attribute_type_id'], 'attribute_name' => $attr['attribute_name']],
                array_merge($attr, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
