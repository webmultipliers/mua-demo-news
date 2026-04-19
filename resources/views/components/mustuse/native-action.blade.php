@props(['block' => [], 'children' => [], 'gates' => [], 'shellState' => []])

@php
    $capability   = $block['capability']   ?? '';
    $label        = $block['label']        ?? ucfirst((string) $capability);
    $message      = (string) ($block['message']      ?? '');
    $intensity    = (string) ($block['intensity']    ?? 'medium');
    $feedback     = (string) ($block['feedback']     ?? 'none');
    $fallbackText = (string) ($block['fallbackText'] ?? '');
    $showResult   = (bool)   ($block['showResult']   ?? false);
    $callbackType = (string) ($block['callbackType'] ?? 'state');
    $callbackSlot = (string) ($block['callbackSlot'] ?? '');
    $callbackUrl  = (string) ($block['callbackUrl']  ?? '');

    // Browser variants share one shell handler; the variant just
    // determines how the URL opens. Preserves the original contract
    // for shells that haven't upgraded to the extended switch.
    $shellCapability = match ($capability) {
        'browser_inapp', 'browser_system', 'browser_auth' => 'browser',
        default                                           => $capability,
    };

    // Capabilities that take a string payload (URL / message / seed text).
    // Browser + share reuse `callbackUrl` for back-compat; the newer
    // dialog/scanner/geolocation paths read from `message`.
    $payload = match ($capability) {
        'browser_inapp', 'browser_system', 'browser_auth', 'share' => $callbackUrl !== '' ? $callbackUrl : $message,
        default                                                    => $message,
    };

    // Result row reads `$shellState['lastCallback'][<capability>]` from
    // the parent NativeEdge — populated by the matching #[OnNative]
    // listener after the native round-trip completes. Displayed as a
    // shallow key/value list because there's no per-capability UI
    // template here — publishers wanting richer output build their own
    // block.
    $resultKey    = $capability;
    $lastCallback = is_array($shellState['lastCallback'] ?? null) ? $shellState['lastCallback'] : [];
    $result       = is_array($lastCallback[$resultKey] ?? null) ? $lastCallback[$resultKey] : [];
@endphp

@if ($capability !== '')
    <button type="button"
            class="mua-native-action mua-native-action--{{ $capability }}"
            wire:click="triggerCapability(@js($shellCapability), @js($callbackSlot), @js($payload), @js(['intensity' => $intensity, 'feedback' => $feedback]))">
        <span class="mua-native-action__label">{{ $label }}</span>
    </button>

    @if ($showResult && $result !== [])
        <dl class="mua-native-action__result" aria-live="polite">
            @foreach ($result as $k => $v)
                <dt>{{ $k }}</dt>
                <dd>{{ is_scalar($v) ? (string) $v : json_encode($v) }}</dd>
            @endforeach
        </dl>
    @endif

    @if ($fallbackText !== '')
        <noscript class="mua-native-action__fallback">{{ $fallbackText }}</noscript>
    @endif
@endif
