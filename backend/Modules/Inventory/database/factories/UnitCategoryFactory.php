<?php

declare(strict_types=1);

namespace Modules\Inventory\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\UnitCategory;

class UnitCategoryFactory extends Factory
{
    protected $model = UnitCategory::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name'        => $this->faker->unique()->word(),
            'description' => $this->faker->optional()->sentence(),
        ];
    }
}
