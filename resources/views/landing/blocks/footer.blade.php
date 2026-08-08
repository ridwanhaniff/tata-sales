@php
    $config = $section->config;
    $heading = $config['heading'] ?? '';
    $subheading = $config['subheading'] ?? '';
    $address = $config['address'] ?? '';
    $phone = $config['phone'] ?? '';
    $links = $config['links'] ?? [];
    $footer = $config['footer_text'] ?? '';
@endphp

<footer class="bg-gray-900 text-gray-300">
    <div class="mx-auto max-w-7xl px-4 py-12 lg:px-8">
        <div class="grid grid-cols-1 gap-8 sm:grid-cols-3">
            <div>
                @if ($heading)
                    <h3 class="text-lg font-semibold text-white">{{ $heading }}</h3>
                @endif
                @if ($subheading)
                    <p class="mt-2 text-sm">{{ $subheading }}</p>
                @endif
            </div>
            @if ($links)
                <div>
                    <h3 class="text-sm font-semibold text-white">Tautan</h3>
                    <ul class="mt-2 space-y-1 text-sm">
                        @foreach ($links as $link)
                            <li><a href="{{ $link['url'] ?? '#' }}" class="hover:text-white">{{ $link['label'] ?? '' }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if ($address || $phone)
                <div>
                    <h3 class="text-sm font-semibold text-white">Kontak</h3>
                    <ul class="mt-2 space-y-1 text-sm">
                        @if ($address)
                            <li>{{ $address }}</li>
                        @endif
                        @if ($phone)
                            <li>{{ $phone }}</li>
                        @endif
                    </ul>
                </div>
            @endif
        </div>
        @if ($footer)
            <p class="mt-8 border-t border-gray-800 pt-6 text-center text-xs text-gray-500">{{ $footer }}</p>
        @endif
    </div>
</footer>
