@php
    $config = $section->config;
    $heading = $config['heading'] ?? '';
    $products = $config['products'] ?? [];
@endphp

<section class="bg-gray-50">
    <div class="mx-auto max-w-7xl px-4 py-16 lg:px-8">
        @if ($heading)
            <h2 class="text-center text-3xl font-bold tracking-tight">{{ $heading }}</h2>
        @endif
        <div class="mt-10 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($products as $product)
                <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200" @click="track('product_view', { product: '{{ $product['id'] ?? '' }}' })">
                    @if (! empty($product['images']))
                        <img src="{{ $product['images'][0] }}" alt="{{ $product['name'] }}" class="h-48 w-full object-cover">
                    @endif
                    <div class="p-5">
                        <h3 class="font-semibold">{{ $product['name'] }}</h3>
                        @if (isset($product['base_price']))
                            <p class="mt-2 text-lg font-semibold text-orange-600">
                                {{ 'Rp '.number_format($product['base_price'], 0, ',', '.') }}
                            </p>
                        @endif
                        @if (($config['show_cta'] ?? true) && ! empty($config['cta_text']) && ! empty($config['cta_link']))
                            <a href="{{ $config['cta_link'] }}" class="mt-4 inline-block text-sm font-semibold text-orange-600 hover:underline">
                                {{ $config['cta_text'] }}
                            </a>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>
