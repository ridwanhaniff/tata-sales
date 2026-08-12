@php
    $tenantName = ! empty($tenant->name) ? $tenant->name : config('app.name', 'TATA Sales');
    $waNumber = preg_replace('/\D/', '', (string) ($tenant->settings['whatsapp'] ?? ''));
    $footerSection = $sections->first(fn ($s) => $s->block_type === 'footer');
    $contactHref = $waNumber
        ? 'https://wa.me/' . $waNumber
        : ($footerSection ? '#section-' . $footerSection->id : '#kontak');

    $first = $sections->first();
    $darkTop = $first && $first->block_type === 'hero' && ! empty($first->config['background']);

    $nav = [];
    $seen = [];
    foreach ($sections as $section) {
        $label = match ($section->block_type) {
            'product_grid', 'product' => 'Produk',
            'banner' => 'Promo',
            'faq' => 'FAQ',
            'default' => $section->config['heading'] ?? null,
            default => null,
        };
        if (! $label || isset($seen[$label])) {
            continue;
        }
        $seen[$label] = true;
        $nav[] = ['label' => $label, 'href' => '#section-' . $section->id];
    }
@endphp
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $page->seo_title ?: $page->title }}</title>
    <meta name="theme-color" content="#ffffff">

    @if ($page->seo_description)
        <meta name="description" content="{{ $page->seo_description }}">
    @endif
    @if ($page->seo_keywords)
        <meta name="keywords" content="{{ $page->seo_keywords }}">
    @endif
    @if ($page->canonical_url)
        <link rel="canonical" href="{{ $page->canonical_url }}">
    @endif
    @if ($page->og_title || $page->seo_title)
        <meta property="og:title" content="{{ $page->og_title ?: $page->seo_title }}">
    @endif
    @if ($page->seo_description)
        <meta property="og:description" content="{{ $page->seo_description }}">
    @endif
    @if ($page->og_image_url)
        <meta property="og:image" content="{{ $page->og_image_url }}">
    @endif

    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-white font-sans text-neutral-900 antialiased" id="top" x-data="{
    visitorId: localStorage.getItem('tata_visitor_id') || (() => {
        const id = crypto.randomUUID ? crypto.randomUUID() : Date.now() + Math.random().toString(36);
        localStorage.setItem('tata_visitor_id', id);
        return id;
    })(),
    track(eventType, extra = {}) {
        const payload = { event_type: eventType, visitor_id: this.visitorId, event_data: extra };
        fetch('/api/v1/events', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Tenant-ID': '{{ $tenant->id }}' },
            body: JSON.stringify(payload),
        }).catch(() => {});
    }
}">

    <header
        class="fixed inset-x-0 top-0 z-50 border-b transition-[background-color,border-color,box-shadow] duration-200"
        :class="scrolled
            ? 'border-neutral-200/80 bg-white/90 text-neutral-900 shadow-xs backdrop-blur-md'
            : '{{ $darkTop ? 'border-transparent bg-transparent text-white' : 'border-transparent bg-transparent text-neutral-900' }}'"
        x-data="{ scrolled: false, open: false }"
        x-init="$watch('open', (v) => document.body.classList.toggle('overflow-hidden', v))"
        @scroll.window.passive="scrolled = window.scrollY > 12"
        @keydown.escape.window="open = false"
    >
        <div class="container-page flex h-16 items-center justify-between md:h-[72px]">
            <a href="#top" class="flex min-h-[44px] items-center gap-2.5" aria-label="{{ $tenantName }}">
                <span class="flex h-8 w-8 items-center justify-center rounded-[10px] bg-primary text-sm font-bold text-white">T</span>
                <span class="text-[17px] font-semibold tracking-tight">{{ $tenantName }}</span>
            </a>

            <nav class="hidden items-center gap-1 lg:flex" aria-label="Navigasi utama">
                @foreach ($nav as $item)
                    <a href="{{ $item['href'] }}"
                       class="rounded-md px-3 py-2 text-sm font-medium opacity-90 transition-opacity duration-150 hover:opacity-100">
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>

            <div class="flex items-center gap-3">
                <a href="{{ $contactHref }}"
                   @click="track('whatsapp_click', { context: 'header' })"
                   class="btn btn-primary btn-sm hidden sm:inline-flex"
                   {{ $waNumber ? 'target="_blank" rel="noopener"' : '' }}>
                    Hubungi Sales
                </a>

                <button type="button"
                        class="relative flex h-11 w-11 items-center justify-center rounded-md lg:hidden"
                        @click="open = !open"
                        :aria-expanded="open"
                        aria-controls="mobile-menu"
                        aria-label="Buka menu navigasi">
                    <svg x-show="!open" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16" />
                    </svg>
                    <svg x-cloak x-show="open" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
                    </svg>
                </button>
            </div>
        </div>

        <div x-cloak x-show="open" id="mobile-menu" class="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true" aria-label="Menu navigasi">
            <div class="absolute inset-0 bg-neutral-900/40 backdrop-blur-sm" @click="open = false"></div>
            <div class="absolute inset-y-0 right-0 flex w-[320px] max-w-[85vw] flex-col bg-white shadow-lg"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="translate-x-full"
                 x-transition:enter-end="translate-x-0"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="translate-x-0"
                 x-transition:leave-end="translate-x-full">
                <div class="flex h-16 items-center justify-between border-b border-neutral-100 px-6">
                    <span class="text-[17px] font-semibold tracking-tight text-neutral-900">{{ $tenantName }}</span>
                    <button type="button"
                            class="flex h-11 w-11 items-center justify-center rounded-md text-neutral-500 hover:bg-neutral-50"
                            @click="open = false"
                            aria-label="Tutup menu">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
                        </svg>
                    </button>
                </div>
                <nav class="flex flex-1 flex-col gap-1 px-4 py-6" aria-label="Navigasi seluler">
                    @foreach ($nav as $item)
                        <a href="{{ $item['href'] }}"
                           @click="open = false"
                           class="flex min-h-[44px] items-center rounded-md px-4 text-[15px] font-medium text-neutral-800 hover:bg-neutral-50">
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </nav>
                <div class="border-t border-neutral-100 p-4">
                    <a href="{{ $contactHref }}"
                       @click="track('whatsapp_click', { context: 'mobile_menu' }); open = false"
                       class="btn btn-primary w-full"
                       {{ $waNumber ? 'target="_blank" rel="noopener"' : '' }}>
                        Hubungi Sales
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main>
        @foreach ($sections as $section)
            @php
                $blockView = "landing.blocks.{$section->block_type}";
                $blockView = view()->exists($blockView) ? $blockView : 'landing.blocks.default';
            @endphp
            @include($blockView, ['section' => $section, 'tenant' => $tenant, 'contactHref' => $contactHref])
        @endforeach
    </main>

    @if ($waNumber)
        <a href="{{ $contactHref }}"
           @click="track('whatsapp_click', { context: 'floating' })"
           class="fixed bottom-5 right-5 z-40 flex h-14 w-14 items-center justify-center rounded-full bg-[#25D366] text-white shadow-md transition-shadow duration-200 hover:shadow-lg"
           target="_blank" rel="noopener"
           aria-label="Chat dengan Sales melalui WhatsApp">
            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2zm0 18.15h-.01a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.2 8.2 0 0 1-1.26-4.38c0-4.54 3.7-8.24 8.25-8.24 2.2 0 4.27.86 5.82 2.42a8.18 8.18 0 0 1 2.41 5.83c0 4.54-3.7 8.23-8.24 8.23zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.17.24-.64.8-.78.97-.14.16-.29.18-.54.06-.25-.13-1.05-.39-2-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.02-.38.11-.51.11-.11.25-.29.37-.43.12-.14.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.13-.56-1.35-.77-1.84-.2-.48-.4-.42-.56-.43h-.48c-.17 0-.43.06-.66.31-.22.25-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.15-1.18-.06-.1-.23-.16-.48-.29z" />
            </svg>
        </a>
    @endif
</body>
</html>