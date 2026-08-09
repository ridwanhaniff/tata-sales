<?php

namespace App\Agents\Tools;

use App\Agents\Tools\Contracts\Tool;
use App\Services\Product\ProductService;
use Illuminate\Support\Arr;

class SearchProductsTool implements Tool
{
    public function __construct(private readonly ProductService $products) {}

    public function name(): string
    {
        return 'search_products';
    }

    public function description(): string
    {
        return 'Cari produk di katalog yang di-approve tenant. Satu-satunya sumber data produk. Balasan berupa daftar id, nama, harga, dan stok yang benar-benar ada di database.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Kata kunci pencarian (nama produk, model, kata pada deskripsi)'],
                'category' => ['type' => 'string', 'description' => 'Nama atau slug kategori untuk mempersempit hasil'],
                'budget_max' => ['type' => 'number', 'description' => 'Harga maksimum (Rp, jumlah penuh) yang mampu dibeli customer'],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $arguments): array
    {
        $products = $this->products->search(
            query: (string) Arr::get($arguments, 'query', ''),
            category: Arr::get($arguments, 'category') ? (string) $arguments['category'] : null,
            budgetMax: isset($arguments['budget_max']) ? (int) round((float) $arguments['budget_max']) : null,
            limit: 8,
        );

        return [
            'found_count' => $products->count(),
            'results' => $products->map(fn ($p) => [
                'product_id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'base_price' => (int) $p->base_price,
                'stock_status' => $p->stock_status,
                'category' => $p->category?->name,
                'short_description' => $p->short_description,
            ])->all(),
        ];
    }
}
