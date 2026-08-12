@php
    $config = $section->config;
    $label = $config['promo_label'] ?? ($config['heading'] ? 'Promo' : '');
    $heading = $config['heading'] ?? '';
    $subheading = $config['subheading'] ?? '';
    $background = $config['background'] ?? '';
    $ctaText = $config['cta_text'] ?? null;
    $ctaLink = $config['cta_link'] ?? null;
    $code = $config['code'] ?? '';
    $terms = $config['terms'] ?? '';
    $validUntil = $config['valid_until'] ?? null;
    $deadline = null;
    $expired = true;

    if ($validUntil) {
        try {
            $deadline = \Illuminate\Support\Carbon::parse($validUntil, $tenant->timezone ?? 'Asia/Jakarta');
            $expired = $deadline->isPast();
        } catch (\Throwable $e) {
            $deadline = null;
            $expired = true;
        }
    }
@endphp

<section id="section-{{ $section->id }}" class="relative scroll-mt-16 overflow-hidden bg-neutral-900 text-white">
    @if ($background)
        <img src="{{ $background }}" alt="" aria-hidden="true" class="absolute inset-0 h-full w-full object-cover opacity-25">
        <div class="absolute inset-0 bg-gradient-to-b from-neutral-950/70 to-neutral-950/90" aria-hidden="true"></div>
    @endif

    <div class="container-page section-pad relative text-center">
        @if ($label)
            <p class="eyebrow justify-center text-[#9CC6FF]">{{ $label }}</p>
        @endif

        @if ($heading)
            <h2 class="mx-auto mt-5 max-w-2xl text-3xl font-bold tracking-tight sm:text-4xl">{{ $heading }}</h2>
        @endif

        @if ($subheading)
            <p class="mx-auto mt-4 max-w-xl text-lg leading-relaxed text-neutral-200/80">{{ $subheading }}</p>
        @endif

        @if ($deadline && ! $expired)
            <div class="mt-10"
                 x-data="{
                     end: new Date('{{ $deadline->toIso8601String() }}').getTime(),
                     d: 0, h: 0, m: 0,
                     init() {
                         this.tick();
                         this.timer = setInterval(() => this.tick(), 1000);
                     },
                     tick() {
                         const diff = Math.max(0, this.end - Date.now());
                         this.d = Math.floor(diff / 86400000);
                         this.h = Math.floor((diff % 86400000) / 3600000);
                         this.m = Math.floor((diff % 3600000) / 60000);
                         if (diff === 0) clearInterval(this.timer);
                     }
                 }">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-neutral-400">Berlaku hingga {{ $deadline->translatedFormat('d F Y, H:i') }}</p>
                <div class="mt-4 flex items-center justify-center gap-3">
                    <div class="min-w-[72px] rounded-xl border border-white/10 bg-white/5 px-4 py-4">
                        <p class="text-3xl font-semibold tabular-nums" x-text="String(d).padStart(2, '0')">00</p>
                        <p class="mt-1 text-xs text-neutral-400">Hari</p>
                    </div>
                    <div class="min-w-[72px] rounded-xl border border-white/10 bg-white/5 px-4 py-4">
                        <p class="text-3xl font-semibold tabular-nums" x-text="String(h).padStart(2, '0')">00</p>
                        <p class="mt-1 text-xs text-neutral-400">Jam</p>
                    </div>
                    <div class="min-w-[72px] rounded-xl border border-white/10 bg-white/5 px-4 py-4">
                        <p class="text-3xl font-semibold tabular-nums" x-text="String(m).padStart(2, '0')">00</p>
                        <p class="mt-1 text-xs text-neutral-400">Menit</p>
                    </div>
                </div>
            </div>
        @elseif ($deadline && $expired)
            <p class="mt-8 inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm text-neutral-400">
                Promo telah berakhir
            </p>
        @endif

        @if ($code)
            <div class="mx-auto mt-9 flex max-w-md items-center justify-between gap-4 rounded-xl border border-white/10 bg-white/5 p-4"
                 x-data="{ copied: false }">
                <div class="text-left">
                    <p class="text-xs uppercase tracking-wide text-neutral-400">Kode promo</p>
                    <p class="mt-0.5 font-mono text-xl font-semibold tracking-widest">{{ $code }}</p>
                </div>
                <button type="button"
                        class="btn btn-sm border-white/20 bg-white/10 text-white hover:bg-white/20"
                        x-text="copied ? 'Tersalin ✓' : 'Salin Kode'"
                        @click="
                            navigator.clipboard?.writeText('{{ $code }}').catch(() => {});
                            track('promo_copy', { code: '{{ $code }}' });
                            copied = true;
                            setTimeout(() => copied = false, 2500);
                        ">
                    Salin Kode
                </button>
            </div>
        @endif

        @if ($ctaText && $ctaLink)
            <div class="mt-9">
                <a href="{{ $ctaLink }}"
                   @click="track('cta_click', { section: '{{ $section->id }}', cta: 'promo' })"
                   class="btn btn-primary">
                    {{ $ctaText }}
                </a>
            </div>
        @endif

        @if ($terms)
            <p class="mt-6 text-xs text-neutral-500">{{ $terms }}</p>
        @endif
    </div>
</section>