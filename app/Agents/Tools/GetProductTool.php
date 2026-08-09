<?php

namespace App\Agents\Tools;

use App\Agents\Tools\Contracts\Tool;
use App\Services\Product\ProductService;
use Illuminate\Support\Arr;

class GetProductTool implements Tool
{
    public function __construct(private readonly ProductService $products) {}

    public function name(): string
    {
        return 'get_product';
    }

    public function description(): string
    {
        return 'Ambil detail satu produk berdasarkan product_id (dari search_products). Berisi spesifikasi dan harga dari database; status stok disertakan.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_id' => ['type' => 'string', 'description' => 'id produk UUID dari hasil search_products'],
            ],
            'required' => ['product_id'],
        ];
    }

    public function execute(array $arguments): array
    {
        $product = $this->products->find((string) Arr::get($arguments, 'product_id', ''));

        if (! $product) {
            return ['found' => false, 'reason' => 'Produk tidak ditemukan atau tidak tersedia.'];
        }

        return [
            'found' => true,
            'product' => [
                'product_id' => $product->id,
                'name' => $product->name,
                'base_price' => (int) $product->base_price,
                'stock_status' => $product->stock_status,
                'category' => $product->category?->name,
                'description' => $product->description,
                'specifications' => $product->attributes->map(fn ($a) => [
                    'key' => $a->attribute_key,
                    'value' => $a->attribute_value,
                    'type' => $a->attribute_type,
                ])->all(),
            ],
        ];
    }
}
