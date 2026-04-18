{{--
    Default layout for full-page Livewire components (NativeEdge, etc).

    Livewire 3 resolves `components.layouts.app` when a route points directly
    at a Component class (see `routes/web.php`), so this file MUST exist.

    Asset strategy (three stacked layers, each optional):
      1. Shell base — tokens + body reset + layout frame from
         resources/css/app.css. Always emitted.
      2. Branding vars — primary/accent/background CSS custom properties
         from the manifest, so block SCSS inherits the publisher's palette.
      3. Per-block CSS — the BlockAssetCollector reads only the CSS for
         blocks actually present on the current screen, sourced from
         Blockstudio's compiled _dist/ (mirrored into public/blocks/ by
         BuildAssembler). Same bytes that render in the WP editor + site.

    No global "shell.css" with every block's rules baked in. A screen with
    just a hero + article-list only inlines hero.css + article-list.css.
--}}
@php
    $hasViteManifest = file_exists(public_path('build/manifest.json'))
        || file_exists(public_path('build/.vite/manifest.json'));
    $baseCssPath = resource_path('css/app.css');
    $baseCss     = (! $hasViteManifest && is_file($baseCssPath))
        ? (string) @file_get_contents($baseCssPath)
        : '';

    $branding     = data_get($manifest ?? [], 'branding', []);
    $brandingVars = array_filter([
        '--mua-color-primary'    => $branding['primary_color']    ?? null,
        '--mua-color-accent'     => $branding['accent_color']     ?? null,
        '--mua-color-background' => $branding['background_color'] ?? null,
    ]);

    // Per-block CSS/JS for the current screen — populated by NativeEdge::render()
    // via layoutData(); undefined on non-NativeEdge layouts so guard with ??.
    $blockCss = $inlineBlockCss ?? '';
    $blockJs  = $inlineBlockJs  ?? '';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="{{ $branding['primary_color'] ?? '#1e1e1e' }}">
    <title>{{ $title ?? config('app.name', 'App') }}</title>

    @if ($hasViteManifest)
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @elseif ($baseCss !== '')
        <style id="mua-shell-base">{!! $baseCss !!}</style>
    @endif

    @if (! empty($brandingVars))
        <style id="mua-branding-vars">:root {
            @foreach ($brandingVars as $prop => $value)
                {{ $prop }}: {{ $value }};
            @endforeach
        }</style>
    @endif

    @if ($blockCss !== '')
        <style id="mua-block-styles">{!! $blockCss !!}</style>
    @endif

    @livewireStyles
</head>
<body>
    {{ $slot }}

    @if ($blockJs !== '')
        <script id="mua-block-scripts" type="module">{!! $blockJs !!}</script>
    @endif

    @livewireScripts
</body>
</html>
