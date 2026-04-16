@props(['block' => [], 'children' => [], 'gates' => []])

@php
    $text  = $block['text']  ?? ($block['title'] ?? '');
    $level = (int) ($block['level'] ?? 2);
    if ($level < 2 || $level > 6) {
        $level = 2;
    }
    $tag = 'h' . $level;
    $showRule = $block['show_rule'] ?? true;
@endphp

<div class="mua-section-heading">
    <{{ $tag }} class="mua-section-heading__text">{{ $text }}</{{ $tag }}>
    @if ($showRule)
        <hr class="mua-section-heading__rule" />
    @endif
</div>
