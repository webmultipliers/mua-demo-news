{{--
    Dispatch a single manifest block to its matching renderer.

    Dispatch table, decided by the block's `native.json` (projected by
    BuildAssembler into `public/blocks/{slug}/native.json`):

      1. slug === "query-loop"     → <livewire:query-loop>
                                      (fetches N items, iterates
                                      post-template's innerBlocks with
                                      per-item context — ONE Livewire
                                      component per loop, not per item)
      2. slug === "post-template"  → skipped at top level
                                      (parent QueryLoop renders this)
      3. dataSource present        → <livewire:dynamic-block>
                                      (single-block fetch; SWR cache)
      4. consumes_context present  → plain Blade with $context prop
      5. neither                   → plain Blade, static render

    $block:   from the manifest — { type, attributes, children|innerBlocks }
    $context: current route/loop context (may be null on static screens);
              always passed through to children
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
    {{-- unknown block type; silently skip so old shells don't break on new pub blocks --}}

@elseif ($slug === 'query-loop')
    {{--
        Query Loop gets a bespoke Livewire component so the loop can
        iterate N items while sharing a single HTTP request + SWR cache.
        We pass its `children` (innerBlocks incl. the post-template) so
        QueryLoop can iterate against them without re-reading the manifest.
    --}}
    <livewire:query-loop
        :attributes="$blockAttributes"
        :children="$children"
        :context="$context"
        wire:key="loop-{{ md5(serialize($blockAttributes) . count($children)) }}" />

@elseif ($slug === 'post-template')
    {{--
        post-template is the iteration marker inside a query-loop. If it
        ever surfaces at the top level (author dropped one outside a
        loop), render its children once without iteration so content
        isn't invisible — but don't pretend to loop.
    --}}
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
