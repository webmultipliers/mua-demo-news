{{--
    Dispatch a single manifest block to its matching Blade component.
    Included recursively by container blocks (auth-gate) for children.

    $block:
        type        string, e.g. "mustuse-apps-pub/hero"
        attributes  array, block-specific
        children    array, optional nested blocks (alias: innerBlocks)
--}}
@php
    $type = $block['type'] ?? ($block['blockName'] ?? '');
    $slug = \Illuminate\Support\Str::after($type, 'mustuse-apps-pub/');
    // Components live under `mustuse/` — BuildAssembler projects each
    // src/Blocks/{slug}/mobile.blade.php into this subdirectory so the
    // blueprint stays generic and the pub owns the full component lifecycle.
    $componentAlias = $slug !== '' ? 'components.mustuse.' . $slug : null;
    $blockAttributes = $block['attributes'] ?? [];
    $children = $block['children'] ?? ($block['innerBlocks'] ?? []);
@endphp

@if ($componentAlias && View::exists($componentAlias))
    @component($componentAlias, ['block' => $blockAttributes, 'children' => $children])
    @endcomponent
@endif
