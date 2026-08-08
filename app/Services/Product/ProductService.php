<?php

namespace App\Services\Product;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductService
{
    public function create(array $data, ?string $tenantId = null): Product
    {
        $data = $this->prepare($data, $tenantId);

        return DB::transaction(function () use ($data) {
            $product = Product::create($data['product']);

            $this->syncVariants($product, $data['variants'] ?? []);
            $this->syncAttributes($product, $data['attributes'] ?? []);

            return $product->fresh();
        });
    }

    public function update(Product $product, array $data): Product
    {
        $data = $this->prepare($data, $product->tenant_id, $product);

        return DB::transaction(function () use ($data, $product) {
            $before = $this->criticalFields($product->getAttributes());

            $product->update($data['product']);

            $this->syncVariants($product, $data['variants'] ?? null);
            $this->syncAttributes($product, $data['attributes'] ?? null);

            $after = $this->criticalFields($product->fresh()->getAttributes());

            if ($before !== $after) {
                AuditLogger::log('product.price_changed', 'product', $product->id, $before, $after);
            }

            return $product->fresh();
        });
    }

    public function publish(Product $product): Product
    {
        $product->update([
            'status' => 'published',
            'published_at' => $product->published_at ?? now(),
        ]);

        return $product->fresh();
    }

    public function unpublish(Product $product): Product
    {
        $product->update([
            'status' => 'draft',
            'published_at' => null,
        ]);

        return $product->fresh();
    }

    private function prepare(array $data, ?string $tenantId, ?Product $product = null): array
    {
        $productData = Arr::only($data, [
            'name', 'slug', 'description', 'short_description', 'base_price',
            'status', 'stock_status', 'featured', 'category_id',
            'seo_title', 'seo_description', 'og_image_url',
        ]);

        if ($product === null) {
            $productData['tenant_id'] = $tenantId;
        }

        if (isset($productData['featured'])) {
            $productData['featured'] = (bool) $productData['featured'];
        }

        if (isset($data['slug']) && $data['slug'] !== '') {
            $productData['slug'] = $data['slug'];
        } elseif ($product === null && isset($data['name'])) {
            $productData['slug'] = Str::slug($data['name']);
        }

        if ($product !== null) {
            $this->assertUniqueSlug($product, $productData['slug'] ?? null);
        }

        return [
            'product' => $productData,
            'variants' => $data['variants'] ?? null,
            'attributes' => $data['attributes'] ?? null,
        ];
    }

    private function syncVariants(Product $product, ?array $variants): void
    {
        if ($variants === null) {
            return;
        }

        $product->variants()->delete();

        foreach ($variants as $variant) {
            if (isset($variant['id']) && ProductVariant::where('id', $variant['id'])->exists()) {
                $product->variants()->create(Arr::except($variant, ['id']));
            } else {
                $product->variants()->create($variant);
            }
        }
    }

    private function syncAttributes(Product $product, ?array $attributes): void
    {
        if ($attributes === null) {
            return;
        }

        $product->attributes()->delete();

        foreach ($attributes as $attribute) {
            $product->attributes()->create([
                'attribute_key' => $attribute['key'] ?? $attribute['attribute_key'] ?? null,
                'attribute_value' => $attribute['value'] ?? $attribute['attribute_value'] ?? null,
                'attribute_type' => $attribute['type'] ?? $attribute['attribute_type'] ?? 'text',
            ]);
        }
    }

    private function assertUniqueSlug(Product $product, ?string $slug): void
    {
        if (! $slug) {
            return;
        }

        $exists = Product::query()
            ->where('tenant_id', $product->tenant_id)
            ->where('slug', $slug)
            ->where('id', '!=', $product->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'slug' => ['Slug sudah dipakai.'],
            ]);
        }
    }

    private function criticalFields(array $attributes): array
    {
        $fields = array_merge([
            'base_price' => null,
            'category_id' => null,
            'status' => null,
            'stock_status' => null,
            'featured' => null,
        ], Arr::only($attributes, ['base_price', 'category_id', 'status', 'stock_status', 'featured']));

        return collect($fields)
            ->map(fn ($value) => is_numeric($value) ? (float) $value : $value)
            ->all();
    }
}
