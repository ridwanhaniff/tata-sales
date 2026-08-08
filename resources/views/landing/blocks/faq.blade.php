@php
    $config = $section->config;
    $heading = $config['heading'] ?? 'Pertanyaan Umum';
    $items = $config['items'] ?? [];
@endphp

<section class="bg-white">
    <div class="mx-auto max-w-3xl px-4 py-16 lg:px-8">
        <h2 class="text-center text-3xl font-bold tracking-tight">{{ $heading }}</h2>
        <div class="mt-10 space-y-4">
            @foreach ($items as $index => $item)
                <div class="rounded-lg ring-1 ring-gray-200" x-data="{ open: false }">
                    <button type="button" @click="open = !open"
                            class="flex w-full items-center justify-between px-5 py-4 text-left font-medium">
                        <span>{{ $item['question'] ?? '' }}</span>
                        <span x-text="open ? '−' : '+'" class="text-lg text-orange-600"></span>
                    </button>
                    <div x-show="open" x-cloak class="px-5 pb-4 text-gray-600">
                        {{ $item['answer'] ?? '' }}
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>
