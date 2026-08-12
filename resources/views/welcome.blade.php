<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'TATA Sales') }}</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-white font-sans text-neutral-900 antialiased">
    <header class="border-b border-neutral-100">
        <div class="container-page flex h-16 items-center justify-between">
            <a href="/" class="flex items-center gap-2.5" aria-label="{{ config('app.name', 'TATA Sales') }}">
                <span class="flex h-8 w-8 items-center justify-center rounded-[10px] bg-primary text-sm font-bold text-white">T</span>
                <span class="text-[17px] font-semibold tracking-tight">{{ config('app.name', 'TATA Sales') }}</span>
            </a>
            <nav class="flex items-center gap-3">
                <a href="/l/home" class="btn btn-primary btn-sm">Lihat Demo</a>
            </nav>
        </div>
    </header>

    <main>
        <section class="bg-neutral-50">
            <div class="container-page section-pad">
                <div class="mx-auto max-w-3xl text-center">
                    <p class="eyebrow justify-center">Sistem Penjualan Modern</p>
                    <h1 class="mt-5 text-4xl font-bold tracking-tight sm:text-5xl sm:leading-[1.08]">
                        Sales technology yang kompleks,<br class="hidden sm:block"> terasa sederhana.
                    </h1>
                    <p class="mx-auto mt-6 max-w-xl text-lg leading-relaxed text-neutral-600">
                        TATA Sales menggabungkan landing page, lead capture, CRM, WhatsApp,
                        dan analytics dalam satu platform multi-tenant.
                    </p>
                    <div class="mt-9 flex flex-wrap items-center justify-center gap-3">
                        <a href="/l/home" class="btn btn-primary">Lihat Demo Landing Page</a>
                        <a href="{{ url('/docs') }}" class="btn btn-secondary">Dokumentasi</a>
                    </div>
                </div>
            </div>
        </section>

        <section class="bg-white">
            <div class="container-page section-pad">
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ([
                        ['title' => 'Landing Page Builder', 'desc' => 'Hero, produk, promo, hingga FAQ — build dan publish tanpa developer.'],
                        ['title' => 'Lead & Pipeline', 'desc' => 'Captured lead otomatis di-score, di-assign, dan di-track hingga closing.'],
                        ['title' => 'WhatsApp & AI Agent', 'desc' => 'Handoff kontekstual ke WhatsApp dengan bantuan agent AI untuk kualifikasi.'],
                    ] as $feature)
                        <div class="card flex flex-col p-6">
                            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary-soft">
                                <svg class="h-5 w-5 text-primary-active" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                </svg>
                            </span>
                            <h2 class="mt-4 text-lg font-semibold tracking-tight">{{ $feature['title'] }}</h2>
                            <p class="mt-2 text-sm leading-relaxed text-neutral-600">{{ $feature['desc'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    </main>

    <footer class="bg-neutral-900">
        <div class="container-page flex flex-col items-center justify-between gap-4 py-8 sm:flex-row">
            <p class="text-xs text-neutral-500">© {{ date('Y') }} {{ config('app.name', 'TATA Sales') }}. Hak cipta dilindungi.</p>
            <p class="text-xs text-neutral-500">Laravel v{{ app()->version() }}</p>
        </div>
    </footer>
</body>
</html>