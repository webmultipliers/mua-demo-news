@props(['block' => [], 'children' => [], 'gates' => []])

@php
    $capability = $block['capability'] ?? '';
    $id         = $block['id']         ?? ('native-action-' . md5(serialize($block)));
    $label      = $block['label']      ?? ucfirst($capability);
    $url        = $block['url']        ?? '';
    $icon       = $block['icon']       ?? '';
@endphp

@if ($capability !== '')
    <button type="button"
            class="mua-native-action mua-native-action--{{ $capability }}"
            wire:click="triggerCapability('{{ $capability }}', '{{ $id }}', '{{ $url }}')">
        @if ($icon !== '')
            <span class="mua-native-action__icon" aria-hidden="true">{{ $icon }}</span>
        @endif
        <span class="mua-native-action__label">{{ $label }}</span>
    </button>
@endif
