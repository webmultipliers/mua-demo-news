@props(['block' => [], 'children' => [], 'gates' => []])

@php
    $src     = $block['src']     ?? ($block['url'] ?? '');
    $poster  = $block['poster']  ?? null;
    $caption = $block['caption'] ?? '';
    $autoplay = !empty($block['autoplay']);
    $controls = $block['controls'] ?? true;
@endphp

@if ($src !== '')
    <figure class="mua-video">
        <video class="mua-video__player"
               src="{{ $src }}"
               @if ($poster) poster="{{ $poster }}" @endif
               @if ($autoplay) autoplay muted playsinline @endif
               @if ($controls) controls @endif
               preload="metadata">
            {{ __('Your device does not support HTML5 video.') }}
        </video>
        @if ($caption !== '')
            <figcaption class="mua-video__caption">{{ $caption }}</figcaption>
        @endif
    </figure>
@endif
