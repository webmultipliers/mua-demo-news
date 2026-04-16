@props(['block' => [], 'children' => [], 'gates' => []])

@php
    $content = $block['content'] ?? ($block['html'] ?? '');
    $align   = $block['align']   ?? 'left';
@endphp

{{-- The pub sanitizes content before it ever reaches the manifest;
     the raw HTML is published block output from WordPress core. --}}
<div class="mua-text mua-text--{{ $align }}">
    {!! $content !!}
</div>
