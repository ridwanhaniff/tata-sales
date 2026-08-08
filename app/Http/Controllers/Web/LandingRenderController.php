<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\LandingPage;
use App\Models\Product;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class LandingRenderController extends Controller
{
    public function __invoke(Request $request, string $slug): View
    {
        $tenant = $request->attributes->get('tenant');

        abort_if(! $tenant, 404, 'Tenant tidak ditemukan.');

        $page = LandingPage::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->with(['sections' => fn ($q) => $q->where('status', 'active')->orderBy('sort_order')])
            ->first();

        abort_if(! $page, 404, 'Landing page tidak ditemukan.');

        $sections = $page->sections->map(function ($section) {
            if ($section->block_type === 'product' && ! empty($section->config['product_id'])) {
                $product = Product::query()
                    ->where('id', $section->config['product_id'])
                    ->where('status', 'published')
                    ->with(['images', 'attributes'])
                    ->first();

                if ($product) {
                    $config = $section->config;
                    $config['product'] = $this->productSnapshot($product);
                    $section->config = $config;
                }
            }

            if ($section->block_type === 'product_grid' && ! empty($section->config['product_ids'])) {
                $products = Product::query()
                    ->whereIn('id', $section->config['product_ids'])
                    ->where('status', 'published')
                    ->with(['images', 'attributes'])
                    ->get();

                $config = $section->config;
                $config['products'] = $products->map(fn ($p) => $this->productSnapshot($p))->values()->all();
                $section->config = $config;
            }

            return $section;
        });

        return view('landing.layout', [
            'page' => $page,
            'sections' => $sections,
            'tenant' => $tenant,
        ]);
    }

    private function productSnapshot(Product $product): array
    {
        $attributes = [];
        foreach ($product->attributes as $attribute) {
            $attributes[$attribute->attribute_key] = $attribute->attribute_value;
        }

        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'base_price' => (float) $product->base_price,
            'short_description' => $product->short_description,
            'attributes' => $attributes,
            'images' => $product->images->sortBy('sort_order')->pluck('url')->values()->all(),
        ];
    }
}
