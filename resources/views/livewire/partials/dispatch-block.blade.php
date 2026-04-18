{{--
    Dispatch decisions, by slug + native.json shape:
      query-loop      → <livewire:query-loop>     (iterates per item)
      post-template   → render children once      (surfaced outside a loop)
      dataSource set  → <livewire:dynamic-block>  (single-block fetch)
      otherwise       → plain Blade component     ($context prop always passed)
--}}
@php
    $type            = $block['type'] ?? ($block['blockName'] ?? '');
    $slug            = \Illuminate\Support\Str::after($type, 'mustuse-apps-pub/');
    $blockAttributes = $block['attributes'] ?? [];
    $children        = $block['children'] ?? ($block['innerBlocks'] ?? []);
    $context         = $context ?? null;
    $gates           = $gates   ?? [];

    $nativePath = $slug !== '' && preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)
        ? public_path('blocks/' . $slug . '/native.json')
        : null;
    $native     = $nativePath !== null && is_file($nativePath)
        ? json_decode((string) @file_get_contents($nativePath), true)
        : null;
    $dataSource = is_array($native) && isset($native['dataSource']) && is_array($native['dataSource'])
        ? $native['dataSource']
        : null;

    $componentAlias = $slug !== '' ? 'components.mustuse.' . $slug : null;
@endphp

@if ($slug === '')
    {{-- unknown block type — silently skip so older shells survive new pub blocks --}}

@elseif ($slug === 'query-loop')
    <livewire:query-loop
        :attributes="$blockAttributes"
        :children="$children"
        :context="$context"
        wire:key="loop-{{ md5(serialize($blockAttributes) . count($children)) }}" />

@elseif ($slug === 'post-template')
    {{-- Surfaced outside a QueryLoop — render children once so content isn't invisible. --}}
    @foreach ($children as $inner)
        @include('livewire.partials.dispatch-block', [
            'block'   => $inner,
            'context' => $context,
            'gates'   => $gates,
        ])
    @endforeach

@elseif ($dataSource)
    <livewire:dynamic-block
        :slug="$slug"
        :attributes="$blockAttributes"
        :context="$context"
        wire:key="dyn-{{ $slug }}-{{ md5(serialize($blockAttributes)) }}" />

@elseif ($componentAlias && View::exists($componentAlias))
    @component($componentAlias, [
        'block'    => $blockAttributes,
        'children' => $children,
        'gates'    => $gates,
        'context'  => $context,
    ])
    @endcomponent
@endif
