<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $page->seo_title ?: $page->title }}</title>

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

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans text-gray-900 antialiased" x-data="{
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
    @foreach ($sections as $section)
        @php
            $blockView = "landing.blocks.{$section->block_type}";
            $blockView = view()->exists($blockView) ? $blockView : 'landing.blocks.default';
        @endphp
        @include($blockView, ['section' => $section])
    @endforeach
</body>
</html>
