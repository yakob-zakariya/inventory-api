<?php

namespace Database\Factories;

use App\Enums\ProformaStatus;
use App\Models\Proforma;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An open proforma for a typed-in customer, without items. Tests that need items
 * create them through ProformaManager so the total matches.
 *
 * @extends Factory<Proforma>
 */
class ProformaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => 'PRF-'.fake()->unique()->numerify('9#####'),
            'customer_name' => fake()->name(),
            'proforma_date' => today(),
            'valid_until' => today()->addDays(7),
            'total_amount' => '0.00',
            'status' => ProformaStatus::Open,
        ];
    }
}
