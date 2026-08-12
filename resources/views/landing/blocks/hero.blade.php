@php
    $config = $section->config;
    $eyebrow = $config['eyebrow'] ?? '';
    $heading = $config['heading'] ?? '';
    $subheading = $config['subheading'] ?? '';
    $background = $config['background'] ?? '';
    $ctaText = $config['cta_text'] ?? null;
    $ctaLink = $config['cta_link'] ?? null;
    $cta2Text = $config['cta2_text'] ?? null;
    $cta2Link = $config['cta2_link'] ?? null;
    $trust = $config['trust'] ?? [];
    $trustText = $config['trust_text'] ?? '';
    $dark = ! empty($background);
@endphp

<section id="section-{{ $section->id }}" class="relative scroll-mt-16 overflow-hidden {{ $dark ? 'bg-neutral-900 text-white' : 'bg-neutral-50 text-neutral-900' }}">
    @if ($background)
        <img src="{{ $background }}" alt="" aria-hidden="true"
             class="absolute inset-0 h-full w-full object-cover"
             fetchpriority="high">
        <div class="absolute inset-0 bg-gradient-to-r from-neutral-950/95 via-neutral-950/80 to-neutral-950/45" aria-hidden="true"></div>
    @endif

    <div class="container-page section-pad relative">
        <div class="grid items-center gap-12 lg:grid-cols-12 lg:gap-16">
            <div class="lg:col-span-7">
                @if ($eyebrow)
                    <p class="eyebrow {{ $dark ? 'text-[#9CC6FF]' : '' }}">{{ $eyebrow }}</p>
                @endif

                @if ($heading)
                    <h1 class="mt-5 max-w-3xl text-4xl font-bold leading-[1.1] tracking-[-0.025em] sm:text-5xl sm:leading-[1.08] sm:tracking-[-0.03em] lg:text-[56px] lg:leading-[1.06]">
                        {{ $heading }}
                    </h1>
                @endif

                @if ($subheading)
                    <p class="mt-6 max-w-xl text-lg leading-relaxed {{ $dark ? 'text-neutral-200/80' : 'text-neutral-600' }}">
                        {{ $subheading }}
                    </p>
                @endif

                <div class="mt-9 flex flex-wrap items-center gap-3">
                    @if ($ctaText && $ctaLink)
                        <a href="{{ $ctaLink }}"
                           @click="track('cta_click', { section: '{{ $section->id }}', cta: 'primary' })"
                           class="btn btn-primary">
                            {{ $ctaText }}
                        </a>
                    @endif
                    @if ($cta2Text && $cta2Link)
                        <a href="{{ $cta2Link }}"
                           @click="track('cta_click', { section: '{{ $section->id }}', cta: 'secondary' })"
                           class="btn {{ $dark ? 'border-white/25 bg-white/10 text-white hover:bg-white/20' : 'btn-secondary' }}">
                            {{ $cta2Text }}
                        </a>
                    @endif
                </div>

                @if ($trustText)
                    <p class="mt-8 text-sm {{ $dark ? 'text-neutral-300/70' : 'text-neutral-500' }}">{{ $trustText }}</p>
                @endif
                @if ($trust)
                    <ul class="mt-8 flex flex-wrap gap-x-6 gap-y-3">
                        @foreach ($trust as $item)
                            <li class="flex items-center gap-2 text-sm {{ $dark ? 'text-neutral-200/80' : 'text-neutral-600' }}">
                                <svg class="h-4 w-4 shrink-0 {{ $dark ? 'text-[#9CC6FF]' : 'text-primary' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                </svg>
                                {{ $item }}
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @if ($background)
                <div class="lg:col-span-5">
                    <div class="relative aspect-[4/3] overflow-hidden rounded-xl shadow-lg">
                        <img src="{{ $background }}" alt="{{ $heading ?: $tenant->name }}"
                             class="h-full w-full object-cover"
                             loading="lazy">
                    </div>
                </div>
            @endif
        </div>
    </div>
</section>