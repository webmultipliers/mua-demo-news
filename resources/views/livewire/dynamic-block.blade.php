<div
    class="mua-dynamic-block mua-dynamic-block--{{ $slug }}"
    data-stale="{{ $stale ? 'true' : 'false' }}"
    data-loading="{{ $loading ? 'true' : 'false' }}"
    wire:key="dynamic-{{ $slug }}-{{ md5(serialize($attributes)) }}">
    @php
        // Each block contributes its own `mobile.blade.php` → projected at build
        // time to `resources/views/components/mustuse/{slug}.blade.php`. We pass
        // three things in:
        //   - $block:   the manifest attributes the author configured
        //   - $context: the current route / loop context (null on top-level screens)
        //   - $data:    the fetched payload (items, pagination, …)
        $componentAlias = 'components.mustuse.' . $slug;
    @endphp

    @if (View::exists($componentAlias))
        @component($componentAlias, [
            'block'    => $attributes,
            'children' => [],
            'gates'    => [],
            'context'  => $context,
            'data'     => $data,
        ])
        @endcomponent
    @else
        <p class="mua-dynamic-block__missing">Missing renderer for <code>{{ $slug }}</code>.</p>
    @endif
</div>
