<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'name' => 'Produk '.fake()->words(2, true),
            'slug' => Str::slug(fake()->unique()->words(3, true)),
            'description' => fake()->paragraph(),
            'base_price' => fake()->numberBetween(10_000_000, 500_000_000),
            'status' => 'published',
            'stock_status' => 'available',
            'featured' => false,
        ];
    }
}
