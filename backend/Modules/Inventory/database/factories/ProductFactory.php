<?php

declare(strict_types=1);

namespace Modules\Inventory\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\UnitCategory;
use Modules\Inventory\Models\UnitType;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'product_code' => $this->faker->unique()->regexify('[A-Z]{3}-[0-9]{5}'),
            'name'         => $this->faker->unique()->words(3, true),
            'product_type' => $this->faker->randomElement(['Product', 'Service', 'Bundle', 'Raw Material']),
            'category_id'  => fn () => Category::firstOrCreate(['category_name' => 'Test Category'])->id,
            // Stock cannot move without a stocking UOM. Shared across factory-made
            // products so document lines default to the same unit and need no rate.
            'base_unit_type_id' => fn () => self::defaultUnitType()->id,
            // All boolean flags default to false — no need to specify them
        ];
    }

    public static function defaultUnitType(): UnitType
    {
        $category = UnitCategory::firstOrCreate(['name' => 'Test Unit Category']);

        return UnitType::firstOrCreate(
            ['name' => 'Test Unit', 'unit_category_id' => $category->id],
            ['symbol' => 'tu', 'unit_position' => 'suffix'],
        );
    }
}
