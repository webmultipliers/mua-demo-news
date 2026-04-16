@props(['block' => [], 'children' => [], 'gates' => []])

@php
    $title    = $block['title']    ?? '';
    $subtitle = $block['subtitle'] ?? '';
    $image    = $block['image']    ?? null;
    $cta      = $block['cta']      ?? null;
@endphp

<section class="mua-hero">
    @if (is_array($image) && !empty($image['url']))
        <img class="mua-hero__image" src="{{ $image['url'] }}" alt="{{ $image['alt'] ?? '' }}" />
    @endif

    @if ($title !== '')
        <h1 class="mua-hero__title">{{ $title }}</h1>
    @endif

    @if ($subtitle !== '')
        <p class="mua-hero__subtitle">{{ $subtitle }}</p>
    @endif

    @if (is_array($cta) && !empty($cta['url']))
        <a class="mua-hero__cta" href="{{ $cta['url'] }}">{{ $cta['label'] ?? 'Learn more' }}</a>
    @endif
</section>
