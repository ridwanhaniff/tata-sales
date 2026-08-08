@php
    $config = $section->config;
@endphp

<section class="bg-white">
    <div class="mx-auto max-w-7xl px-4 py-12 lg:px-8">
        <h2 class="text-2xl font-bold tracking-tight">{{ $config['heading'] ?? 'Block' }}</h2>
        @if (! empty($config['subheading']))
            <p class="mt-2 text-gray-600">{{ $config['subheading'] }}</p>
        @endif
        @if (! empty($config['content']))
            <div class="mt-4 text-gray-700">{{ $config['content'] }}</div>
        @endif
    </div>
</section>
