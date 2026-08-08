@php
    $config = $section->config;
    $heading = $config['heading'] ?? '';
    $subheading = $config['subheading'] ?? '';
    $background = $config['background'] ?? '';
    $ctaText = $config['cta_text'] ?? null;
    $ctaLink = $config['cta_link'] ?? null;
    $cta2Text = $config['cta2_text'] ?? null;
    $cta2Link = $config['cta2_link'] ?? null;
@endphp

<section class="relative bg-gray-900 text-white">
    @if ($background)
        <img src="{{ $background }}" alt="" class="absolute inset-0 h-full w-full object-cover opacity-40">
    @endif
    <div class="relative mx-auto max-w-7xl px-4 py-24 sm:py-32 lg:px-8">
        @if ($heading)
            <h1 class="max-w-3xl text-4xl font-bold tracking-tight sm:text-5xl">{{ $heading }}</h1>
        @endif
        @if ($subheading)
            <p class="mt-6 max-w-2xl text-lg text-gray-300">{{ $subheading }}</p>
        @endif
        @if ($ctaText && $ctaLink)
            <a href="{{ $ctaLink }}" @click="track('cta_click', { section: '{{ $section->id }}' })"
               class="mt-8 inline-block rounded-lg bg-orange-500 px-6 py-3 text-sm font-semibold text-white hover:bg-orange-600">
                {{ $ctaText }}
            </a>
        @endif
        @if ($cta2Text && $cta2Link)
            <a href="{{ $cta2Link }}" @click="track('cta_click', { section: '{{ $section->id }}' })"
               class="mt-8 ml-4 inline-block rounded-lg border border-white/30 px-6 py-3 text-sm font-semibold text-white hover:bg-white/10">
                {{ $cta2Text }}
            </a>
        @endif
    </div>
</section>
