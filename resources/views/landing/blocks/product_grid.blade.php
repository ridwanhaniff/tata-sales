@php
    $config = $section->config;
    $heading = $config['heading'] ?? '';
    $subheading = $config['subheading'] ?? '';
    $products = $config['products'] ?? [];
    $ctaText = $config['cta_text'] ?? null;
    $ctaLink = $config['cta_link'] ?? null;

    $fmt = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
@endphp

@if ($products)
    <section id="section-{{ $section->id }}" class="scroll-mt-16 bg-white">
    <div class="container-page section-pad">
        @if ($heading || $subheading)
            <div class="max-w-2xl">
                @if ($heading)
                    <h2 class="text-3xl font-bold tracking-tight sm:text-4xl">{{ $heading }}</h2>
                @endif
                @if ($subheading)
                    <p class="mt-4 text-lg leading-relaxed text-neutral-600">{{ $subheading }}</p>
                @endif
            </div>
        @endif

        <div class="mt-12 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($products as $product)
                @php
                    $productId = $product['id'] ?? '';
                    $specs = collect($product['attributes'] ?? [])->take(2);
                @endphp
                <article class="card card-hover group flex flex-col overflow-hidden"
                         @click="track('product_view', { product: '{{ $productId }}', section: '{{ $section->id }}' })">
                    <a href="{{ $ctaLink ?: '#' }}" class="relative block overflow-hidden bg-neutral-100" tabindex="-1" aria-hidden="true">
                        @if (! empty($product['images']))
                            <img src="{{ $product['images'][0] }}" alt="{{ $product['name'] }}"
                                 class="aspect-[4/3] w-full object-cover transition-transform duration-300 group-hover:scale-[1.02]"
                                 loading="lazy">
                        @else
                            <div class="flex aspect-[4/3] w-full items-center justify-center bg-neutral-100 text-sm text-neutral-400">
                                Belum ada foto
                            </div>
                        @endif
                        @if ($config['tag'] ?? '')
                            <span class="badge absolute left-4 top-4">{{ $config['tag'] }}</span>
                        @endif
                    </a>

                    <div class="flex flex-1 flex-col p-6">
                        <h3 class="text-lg font-semibold tracking-tight">
                            <a href="{{ $ctaLink ?: '#' }}"
                               @click="track('product_click', { product: '{{ $productId }}' })"
                               class="after:absolute after:inset-0">
                                {{ $product['name'] }}
                            </a>
                        </h3>
                        @if (! empty($product['short_description']))
                            <p class="mt-2 line-clamp-2 text-sm leading-relaxed text-neutral-500">
                                {{ $product['short_description'] }}
                            </p>
                        @endif

                        @if ($specs->isNotEmpty())
                            <p class="mt-3 text-sm text-neutral-500">
                                @foreach ($specs as $key => $value)
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="h-1 w-1 rounded-full bg-neutral-300" aria-hidden="true"></span>
                                        {{ $key }}: {{ $value }}
                                    </span>
                                @endforeach
                            </p>
                        @endif

                        <div class="mt-5 flex-1"></div>

                        @if (isset($product['base_price']) && $product['base_price'] > 0)
                            <p class="text-sm text-neutral-500">Mulai dari</p>
                            <p class="text-xl font-semibold tracking-tight text-neutral-900">{{ $fmt($product['base_price']) }}</p>
                        @endif

                        @if ($ctaText && $ctaLink)
                            <a href="{{ $ctaLink }}"
                               @click="track('cta_click', { section: '{{ $section->id }}', product: '{{ $productId }}' })"
                               class="btn btn-secondary btn-sm relative mt-4 w-full">
                                {{ $ctaText }}
                            </a>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</section>
@endif