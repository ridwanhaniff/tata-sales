@php
    $config = $section->config;
    $heading = $config['heading'] ?? '';
    $subheading = $config['subheading'] ?? '';
    $address = $config['address'] ?? '';
    $phone = $config['phone'] ?? '';
    $email = $config['email'] ?? '';
    $hours = $config['hours'] ?? '';
    $links = $config['links'] ?? [];
    $legal = $config['legal'] ?? [];
    $copyright = $config['footer_text'] ?? '';
    $nav = $nav ?? [];
@endphp

<footer id="section-{{ $section->id }}" class="scroll-mt-16 bg-neutral-900 text-neutral-300">
    <div class="container-page pt-16 pb-8">
        <div class="grid grid-cols-1 gap-10 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-1">
                <a href="#top" class="inline-flex items-center gap-2.5" aria-label="{{ $heading ?: 'Ke atas' }}">
                    <span class="flex h-8 w-8 items-center justify-center rounded-[10px] bg-primary text-sm font-bold text-white">T</span>
                    @if ($heading)
                        <span class="text-[17px] font-semibold tracking-tight text-white">{{ $heading }}</span>
                    @endif
                </a>
                @if ($subheading)
                    <p class="mt-4 max-w-xs text-sm leading-relaxed text-neutral-400">{{ $subheading }}</p>
                @endif
            </div>

            @if ($nav || $links)
                <div>
                    <h2 class="text-sm font-semibold text-white">Navigasi</h2>
                    <ul class="mt-4 space-y-2.5 text-sm">
                        @foreach ($nav as $item)
                            <li>
                                <a href="{{ $item['href'] }}" class="text-neutral-400 transition-colors duration-150 hover:text-white">
                                    {{ $item['label'] }}
                                </a>
                            </li>
                        @endforeach
                        @foreach ($links as $link)
                            <li>
                                <a href="{{ $link['url'] ?? '#' }}" class="text-neutral-400 transition-colors duration-150 hover:text-white">
                                    {{ $link['label'] ?? '' }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div>
                <h2 class="text-sm font-semibold text-white">Kontak</h2>
                <ul class="mt-4 space-y-2.5 text-sm text-neutral-400">
                    @if ($address)
                        <li class="flex gap-2.5">
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-neutral-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                            </svg>
                            <span>{{ $address }}</span>
                        </li>
                    @endif
                    @if ($phone)
                        <li class="flex gap-2.5">
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-neutral-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" />
                            </svg>
                            <span>{{ $phone }}</span>
                        </li>
                    @endif
                    @if ($email)
                        <li class="flex gap-2.5">
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-neutral-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                            </svg>
                            <span>{{ $email }}</span>
                        </li>
                    @endif
                    @if ($hours)
                        <li class="flex gap-2.5">
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-neutral-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                            <span>{{ $hours }}</span>
                        </li>
                    @endif
                </ul>
            </div>
        </div>

        <div class="mt-12 flex flex-col items-center justify-between gap-4 border-t border-neutral-800 pt-6 sm:flex-row">
            <p class="text-xs text-neutral-500">{{ $copyright ?: '© ' . date('Y') . ' ' . ($heading ?: config('app.name', 'TATA Sales')) . '. Hak cipta dilindungi.' }}</p>
            @if ($legal)
                <ul class="flex flex-wrap items-center gap-x-5 gap-y-2">
                    @foreach ($legal as $item)
                        <li>
                            <a href="{{ $item['url'] ?? '#' }}" class="text-xs text-neutral-500 transition-colors duration-150 hover:text-neutral-200">
                                {{ $item['label'] ?? '' }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</footer>