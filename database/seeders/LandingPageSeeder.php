<?php

namespace Database\Seeders;

use App\Models\LandingPage;
use App\Models\PageSection;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class LandingPageSeeder extends Seeder
{
    public function run(Tenant $tenant): void
    {
        app()->instance('currentTenant', $tenant);

        $page = LandingPage::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'home'],
            [
                'title' => $tenant->name,
                'template' => $tenant->industry_template ?: 'automotive-v1',
                'status' => 'published',
                'published_at' => now(),
                'seo_title' => $tenant->name.' — Harga & Promo Terbaru',
                'seo_description' => 'Lihat produk terbaru '.$tenant->name.', lengkap dengan harga dan promo.',
            ]
        );

        $products = Product::query()->where('status', 'published')->limit(3)->get();

        $blocks = [
            [
                'block_type' => 'hero',
                'sort_order' => 0,
                'config' => [
                    'heading' => 'Temukan Kendaraan Impian Anda',
                    'subheading' => 'Kunjungi dealer resmi kami untuk penawaran terbaik bulan ini.',
                    'cta_text' => 'Lihat Produk',
                    'cta_link' => '#produk',
                ],
            ],
            [
                'block_type' => 'product_grid',
                'sort_order' => 1,
                'config' => [
                    'heading' => 'Produk Unggulan',
                    'product_ids' => $products->pluck('id')->all(),
                ],
            ],
            [
                'block_type' => 'banner',
                'sort_order' => 2,
                'config' => [
                    'heading' => 'Promo Spesial Bulan Ini',
                    'subheading' => 'Hubungi tim kami untuk informasi lebih lanjut.',
                    'cta_text' => 'Hubungi Kami',
                    'cta_link' => 'https://wa.me/6281200000000',
                ],
            ],
            [
                'block_type' => 'faq',
                'sort_order' => 3,
                'config' => [
                    'heading' => 'Pertanyaan Umum',
                    'items' => [
                        ['question' => 'Apakah bisa test drive?', 'answer' => 'Bisa, silakan hubungi sales kami untuk jadwal.'],
                        ['question' => 'Apakah tersedia simulasi kredit?', 'answer' => 'Ya, tersedia kalkulator kredit di website kami.'],
                    ],
                ],
            ],
            [
                'block_type' => 'footer',
                'sort_order' => 4,
                'config' => [
                    'heading' => $tenant->name,
                    'subheading' => 'Dealer resmi',
                    'address' => 'Jl. Contoh No. 1, Jakarta',
                    'phone' => '0812-0000-0000',
                    'footer_text' => '© '.date('Y').' '.$tenant->name,
                ],
            ],
        ];

        foreach ($blocks as $index => $block) {
            PageSection::query()->updateOrCreate(
                ['landing_page_id' => $page->id, 'block_type' => $block['block_type']],
                [
                    'tenant_id' => $tenant->id,
                    'sort_order' => $index,
                    'config' => $block['config'],
                ]
            );
        }
    }
}
