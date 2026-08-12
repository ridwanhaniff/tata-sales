@php
    $config = $section->config;
@endphp

<section id="section-{{ $section->id }}" class="scroll-mt-16 bg-white">
    <div class="container-page section-pad">
        <div class="mx-auto max-w-3xl">
            @if (! empty($config['eyebrow']))
                <p class="eyebrow">{{ $config['eyebrow'] }}</p>
            @endif
            @if (! empty($config['heading']))
                <h2 class="mt-5 text-3xl font-bold tracking-tight sm:text-4xl">{{ $config['heading'] }}</h2>
            @endif
            @if (! empty($config['subheading']))
                <p class="mt-4 text-lg leading-relaxed text-neutral-600">{{ $config['subheading'] }}</p>
            @endif
            @if (! empty($config['content']))
                <div class="prose-neutral mt-6 text-[15px] leading-relaxed text-neutral-600">{{ $config['content'] }}</div>
            @endif
        </div>
    </div>
</section>