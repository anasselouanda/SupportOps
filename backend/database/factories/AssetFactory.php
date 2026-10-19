<?php

namespace Database\Factories;

use App\Models\Asset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inventory_code' => 'AST-'.fake()->unique()->numerify('##########'),
            'type' => fake()->randomElement(['laptop', 'desktop', 'monitor', 'printer']),
            'brand' => fake()->company(),
            'model' => fake()->bothify('Model-####'),
            'serial_number' => fake()->optional()->bothify('SN-########'),
            'status' => 'available',
            'purchase_date' => null,
        ];
    }
}
