@php
    $config = $section->config;
    $product = $config['product'] ?? null;
    $showPrice = $config['show_price'] ?? true;
    $showCta = $config['show_cta'] ?? true;
    $ctaText = $config['cta_text'] ?? 'Lihat Detail';
    $cta2Text = $config['cta2_text'] ?? null;
    $cta2Link = $config['cta2_link'] ?? null;
    $fmt = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
@endphp

@if ($product)
    <section id="section-{{ $section->id }}" class="scroll-mt-16 bg-neutral-50">
        <div class="container-page section-pad">
            <div class="grid grid-cols-1 items-center gap-12 lg:grid-cols-2 lg:gap-16">
                <div class="relative">
                    @if (! empty($product['images']))
                        <img src="{{ $product['images'][0] }}"
                             alt="{{ $product['name'] }}"
                             class="aspect-[4/3] w-full rounded-xl object-cover shadow-sm"
                             loading="lazy">
                    @else
                        <div class="flex aspect-[4/3] w-full items-center justify-center rounded-xl border border-dashed border-neutral-300 bg-white text-sm text-neutral-400">
                            Belum ada foto
                        </div>
                    @endif
                    @if ($config['tag'] ?? '')
                        <span class="badge absolute left-5 top-5">{{ $config['tag'] }}</span>
                    @endif
                </div>

                <div>
                    @if ($config['eyebrow'] ?? '')
                        <p class="eyebrow">{{ $config['eyebrow'] }}</p>
                    @endif

                    <h2 class="mt-5 text-3xl font-bold tracking-tight sm:text-4xl">{{ $product['name'] }}</h2>

                    @if (! empty($product['short_description']))
                        <p class="mt-4 text-lg leading-relaxed text-neutral-600">{{ $product['short_description'] }}</p>
                    @endif

                    @if ($showPrice && isset($product['base_price']) && $product['base_price'] > 0)
                        <p class="mt-6">
                            <span class="text-sm text-neutral-500">Mulai dari</span>
                            <span class="mt-1 block text-3xl font-semibold tracking-tight text-primary">{{ $fmt($product['base_price']) }}</span>
                        </p>
                    @endif

                    @if (! empty($product['attributes']))
                        <dl class="mt-8 grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-2">
                            @foreach (collect($product['attributes'])->take(4) as $key => $value)
                                <div class="flex items-start gap-3">
                                    <svg class="mt-0.5 h-5 w-5 shrink-0 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                    </svg>
                                    <div>
                                        <dt class="text-sm text-neutral-500">{{ $key }}</dt>
                                        <dd class="font-medium text-neutral-900">{{ $value }}</dd>
                                    </div>
                                </div>
                            @endforeach
                        </dl>
                    @endif

                    <div class="mt-9 flex flex-wrap items-center gap-3">
                        @if ($showCta)
                            <a href="{{ $config['cta_link'] ?? '#' }}"
                               @click="track('cta_click', { section: '{{ $section->id }}', product: '{{ $product['id'] ?? '' }}' })"
                               class="btn btn-primary">
                                {{ $ctaText }}
                            </a>
                        @endif
                        @if ($cta2Text && $cta2Link)
                            <a href="{{ $cta2Link }}"
                               @click="track('cta_click', { section: '{{ $section->id }}', cta: 'secondary' })"
                               class="btn btn-secondary">
                                {{ $cta2Text }}
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>
@endif