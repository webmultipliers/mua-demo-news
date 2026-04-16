@props(['block' => [], 'children' => [], 'gates' => []])

@php
    $gateId = $block['gate_id'] ?? ($block['id'] ?? 'default');
    $prompt = $block['prompt'] ?? 'Authenticate to view this content';
    $isOpen = !empty($gates[$gateId]);
    $children = is_array($children) ? $children : [];
@endphp

<section class="mua-auth-gate" data-gate-id="{{ $gateId }}" data-open="{{ $isOpen ? 'true' : 'false' }}">
    @if ($isOpen)
        @foreach ($children as $child)
            @include('livewire.partials.dispatch-block', ['block' => $child])
        @endforeach
    @else
        <div class="mua-auth-gate__prompt">
            <p class="mua-auth-gate__message">{{ $prompt }}</p>
            <button type="button"
                    class="mua-auth-gate__unlock"
                    wire:click="triggerAuthGate('{{ $gateId }}')">
                {{ __('Unlock') }}
            </button>
        </div>
    @endif
</section>
