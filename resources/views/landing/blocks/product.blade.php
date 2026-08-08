@php
    $config = $section->config;
    $product = $config['product'] ?? null;
    $showPrice = $config['show_price'] ?? true;
    $showCta = $config['show_cta'] ?? true;
    $ctaText = $config['cta_text'] ?? 'Tanya Harga';
@endphp

@if ($product)
    <section class="bg-white">
        <div class="mx-auto max-w-7xl px-4 py-16 lg:px-8">
            <div class="grid grid-cols-1 items-center gap-8 lg:grid-cols-2">
                <div>
                    @if (! empty($product['images']))
                        <img src="{{ $product['images'][0] }}" alt="{{ $product['name'] }}"
                             class="w-full rounded-xl object-cover shadow-lg">
                    @endif
                </div>
                <div>
                    <h2 class="text-3xl font-bold tracking-tight">{{ $product['name'] }}</h2>
                    @if ($showPrice && isset($product['base_price']))
                        <p class="mt-4 text-2xl font-semibold text-orange-600">
                            {{ 'Rp '.number_format($product['base_price'], 0, ',', '.') }}
                        </p>
                    @endif
                    @if (! empty($product['attributes']))
                        <dl class="mt-6 grid grid-cols-2 gap-4">
                            @foreach ($product['attributes'] as $key => $value)
                                <div>
                                    <dt class="text-sm text-gray-500">{{ $key }}</dt>
                                    <dd class="font-medium">{{ $value }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                    @if ($showCta)
                        <a href="{{ $config['cta_link'] ?? '#' }}" @click="track('cta_click', { product: '{{ $product['id'] ?? '' }}' })"
                           class="mt-8 inline-block rounded-lg bg-orange-500 px-6 py-3 text-sm font-semibold text-white hover:bg-orange-600">
                            {{ $ctaText }}
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </section>
@endif
