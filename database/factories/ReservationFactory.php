<?php

namespace Database\Factories;

use App\Enums\ReservationStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReservationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'order_item_id' => OrderItem::factory(),
            'product_id' => Product::factory(),
            'warehouse_id' => Warehouse::factory(),
            'quantity_reserved' => fake()->numberBetween(1, 5),
            'quantity_shipped' => 0,
            'status' => ReservationStatus::Reserved,
            'expires_at' => now()->addMinutes(15),
        ];
    }
}
