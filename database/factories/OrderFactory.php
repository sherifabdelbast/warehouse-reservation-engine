<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_number' => strtoupper(fake()->unique()->bothify('ORD-#####')),
            'status' => 'pending',
            'customer_reference' => fake()->uuid(),
        ];
    }
}
