<?php

namespace Database\Seeders;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $warehouses = Warehouse::factory()->count(2)->create();

        $products = Product::factory()->count(5)->create();

        foreach ($products as $product) {
            foreach ($warehouses as $warehouse) {
                Inventory::factory()->create([
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                    'available_quantity' => fake()->numberBetween(5, 50),
                ]);
            }
        }
    }
}
