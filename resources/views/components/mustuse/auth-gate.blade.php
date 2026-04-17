@props(['block' => [], 'children' => [], 'gates' => []])

@php
    // auth-gate is a container block; children is the inner block tree.
    // Gate id is stable per screen so `triggerAuthGate($id)` can key the
    // unlocked state in $gates. We derive it from the gate heading and a
    // deterministic hash of children so it survives re-renders.
    $heading     = $block['gateHeading']     ?? 'Authentication required';
    $description = $block['gateDescription'] ?? '';
    $ctaLabel    = $block['ctaLabel']        ?? 'Unlock';
    $gateId      = 'gate-' . substr(md5($heading . json_encode($children)), 0, 8);
    $isOpen      = !empty($gates[$gateId]);
    $children    = is_array($children) ? $children : [];
@endphp

<section class="mua-auth-gate" data-gate-id="{{ $gateId }}" data-open="{{ $isOpen ? 'true' : 'false' }}">
    @if ($isOpen)
        @foreach ($children as $child)
            @include('livewire.partials.dispatch-block', ['block' => $child])
        @endforeach
    @else
        <div class="mua-auth-gate__prompt">
            <h3 class="mua-auth-gate__heading">{{ $heading }}</h3>
            @if ($description !== '')
                <p class="mua-auth-gate__description">{{ $description }}</p>
            @endif
            <button type="button"
                    class="mua-auth-gate__unlock"
                    wire:click="triggerAuthGate('{{ $gateId }}')">
                {{ $ctaLabel }}
            </button>
        </div>
    @endif
</section>
