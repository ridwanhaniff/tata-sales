@php
    $config = $section->config;
    $heading = $config['heading'] ?? '';
    $subheading = $config['subheading'] ?? '';
    $background = $config['background'] ?? '';
    $ctaText = $config['cta_text'] ?? null;
    $ctaLink = $config['cta_link'] ?? null;
@endphp

<section class="bg-orange-600 text-white">
    <div class="mx-auto max-w-7xl px-4 py-12 text-center lg:px-8">
        @if ($heading)
            <h2 class="text-2xl font-bold sm:text-3xl">{{ $heading }}</h2>
        @endif
        @if ($subheading)
            <p class="mx-auto mt-3 max-w-2xl text-orange-100">{{ $subheading }}</p>
        @endif
        @if ($ctaText && $ctaLink)
            <a href="{{ $ctaLink }}" @click="track('cta_click', { banner: '{{ $section->id }}' })"
               class="mt-6 inline-block rounded-lg bg-white px-6 py-3 text-sm font-semibold text-orange-600 hover:bg-orange-50">
                {{ $ctaText }}
            </a>
        @endif
    </div>
</section>
