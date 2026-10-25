<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $costCents = fake()->numberBetween(100, 100_000);

        return [
            'category_id' => Category::factory(),
            'sku' => fake()->unique()->bothify('SKU-#####'),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'unit' => fake()->randomElement(['piece', 'bag', 'box', 'kg', 'litre']),
            'cost_price' => number_format($costCents / 100, 2, '.', ''),
            'selling_price' => number_format(intdiv($costCents * 125, 100) / 100, 2, '.', ''),
            'reorder_level' => fake()->numberBetween(0, 20),
        ];
    }
}
