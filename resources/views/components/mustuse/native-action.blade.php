@props(['block' => [], 'children' => [], 'gates' => []])

@php
    $capability   = $block['capability']   ?? '';
    $label        = $block['label']        ?? ucfirst($capability);
    $fallbackText = $block['fallbackText'] ?? '';
    $callbackType = $block['callbackType'] ?? 'state';
    $callbackSlot = $block['callbackSlot'] ?? '';
    $callbackUrl  = $block['callbackUrl']  ?? '';
    // `browser_*` share the same underlying handler on the shell — the
    // variant is just how the URL opens.
    $shellCapability = match ($capability) {
        'browser_inapp', 'browser_system', 'browser_auth' => 'browser',
        default                                           => $capability,
    };
    // Native-action's `$callbackUrl` is the optional POST target for an
    // endpoint-callback; browser-class capabilities repurpose it as the
    // URL to open. Keep the Livewire call generic.
    $passthroughUrl = $capability === 'browser_inapp'  || $capability === 'browser_system' || $capability === 'browser_auth'
        ? $callbackUrl
        : '';
@endphp

@if ($capability !== '')
    <button type="button"
            class="mua-native-action mua-native-action--{{ $capability }}"
            wire:click="triggerCapability('{{ $shellCapability }}', '{{ $callbackSlot }}', @js($passthroughUrl))">
        <span class="mua-native-action__label">{{ $label }}</span>
    </button>

    @if ($fallbackText !== '')
        <noscript class="mua-native-action__fallback">{{ $fallbackText }}</noscript>
    @endif
@endif
