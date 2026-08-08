<?php

namespace App\Services\Product;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductImageService
{
    public const WEBP_QUALITY = 82;

    public function store(Product $product, UploadedFile $file, ?string $altText = null): ProductImage
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                'images' => ['File gambar tidak valid.'],
            ]);
        }

        $source = $file->getRealPath();
        $image = @imagecreatefromstring((string) file_get_contents($source));

        if ($image === false) {
            throw ValidationException::withMessages([
                'images' => ['File harus berupa gambar (JPEG, PNG, WebP, atau GIF).'],
            ]);
        }

        $filename = Str::uuid().'.webp';
        $path = "products/{$product->tenant_id}/{$product->id}/{$filename}";

        ob_start();
        $converted = imagewebp($image, null, self::WEBP_QUALITY);
        $webpData = ob_get_clean();
        imagedestroy($image);

        if (! $converted || $webpData === false) {
            throw ValidationException::withMessages([
                'images' => ['Gagal mengkonversi gambar ke WebP.'],
            ]);
        }

        Storage::disk('public')->put($path, $webpData);

        $maxSort = ProductImage::where('product_id', $product->id)->max('sort_order') ?? 0;

        return ProductImage::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'url' => Storage::disk('public')->url($path),
            'alt_text' => $altText,
            'sort_order' => $maxSort + 1,
        ]);
    }
}
