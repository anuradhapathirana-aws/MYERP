<?php

declare(strict_types=1);

namespace Modules\Inventory\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Enums\UnitPosition;
use Modules\Inventory\Models\UnitCategory;
use Modules\Inventory\Models\UnitType;

class UnitTypeFactory extends Factory
{
    protected $model = UnitType::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'unit_category_id' => UnitCategory::factory(),
            'name'             => $this->faker->unique()->word(),
            'symbol'           => $this->faker->lexify('??'),
            'country'          => $this->faker->optional()->countryCode(),
            'unit_position'    => $this->faker->randomElement(UnitPosition::cases())->value,
        ];
    }
}
