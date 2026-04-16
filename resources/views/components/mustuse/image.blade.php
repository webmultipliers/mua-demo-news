@props(['block' => [], 'children' => [], 'gates' => []])

@php
    $url     = $block['url']     ?? '';
    $alt     = $block['alt']     ?? '';
    $caption = $block['caption'] ?? '';
    $width   = $block['width']   ?? null;
    $height  = $block['height']  ?? null;
@endphp

@if ($url !== '')
    <figure class="mua-image">
        <img class="mua-image__img"
             src="{{ $url }}"
             alt="{{ $alt }}"
             @if ($width)  width="{{ (int) $width }}"   @endif
             @if ($height) height="{{ (int) $height }}" @endif
             loading="lazy" />
        @if ($caption !== '')
            <figcaption class="mua-image__caption">{{ $caption }}</figcaption>
        @endif
    </figure>
@endif
