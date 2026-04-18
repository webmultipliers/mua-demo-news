@props(['block' => [], 'children' => [], 'gates' => []])

@php
    // BlockAttributeNormalizer::expandCategoryList bakes `items` from WP.
    $items      = is_array($block['items'] ?? null) ? $block['items'] : [];
    $activeSlug = (string) ($block['activeSlug'] ?? '');
@endphp

@if (!empty($items))
    <nav class="mua-category-pills" aria-label="Categories">
        @foreach ($items as $item)
            @continue(! is_array($item))
            @php $isActive = $activeSlug !== '' && $activeSlug === ($item['slug'] ?? ''); @endphp
            <div class="mua-category-pills__item {{ $isActive ? 'is-active' : '' }}">
                <a class="mua-pill" href="{{ $item['url'] ?? '#' }}" wire:navigate>{{ $item['name'] ?? '' }}</a>
            </div>
        @endforeach
    </nav>
@endif
