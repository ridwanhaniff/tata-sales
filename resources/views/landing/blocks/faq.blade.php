@php
    $config = $section->config;
    $heading = $config['heading'] ?? 'Pertanyaan Umum';
    $items = $config['items'] ?? [];
@endphp

<section id="section-{{ $section->id }}" class="scroll-mt-16 bg-white">
    <div class="container-page section-pad">
        <div class="mx-auto max-w-3xl">
            <h2 class="text-center text-3xl font-bold tracking-tight sm:text-4xl">{{ $heading }}</h2>

            <div class="mt-12 space-y-3">
                @foreach ($items as $index => $item)
                    <div class="overflow-hidden rounded-lg border border-neutral-200 bg-white transition-colors duration-200"
                         x-data="{ open: false }"
                         :class="open ? 'border-neutral-300 shadow-xs' : ''">
                        <h3>
                            <button type="button"
                                    class="flex w-full items-center justify-between gap-4 px-5 py-4 text-left"
                                    @click="open = !open"
                                    :aria-expanded="open"
                                    :aria-controls="'{{ $section->id }}-answer-{{ $index }}'">
                                <span class="text-[15px] font-medium text-neutral-900">{{ $item['question'] ?? '' }}</span>
                                <svg class="h-5 w-5 shrink-0 text-neutral-400 transition-transform duration-200"
                                     fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                                     :class="open ? 'rotate-180' : ''"
                                     aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                </svg>
                            </button>
                        </h3>
                        <div id="{{ $section->id }}-answer-{{ $index }}"
                             x-show="open"
                             x-cloak
                             x-transition:enter="transition ease-out duration-200"
                             x-transition:enter-start="opacity-0 translate-y-1"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             class="px-5 pb-5 text-[15px] leading-relaxed text-neutral-600">
                            {{ $item['answer'] ?? '' }}
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>