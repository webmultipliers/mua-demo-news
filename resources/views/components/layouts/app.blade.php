{{--
    Default layout for full-page Livewire components (NativeEdge, etc).

    Livewire 3 resolves `components.layouts.app` when a route points directly
    at a Component class (see `routes/web.php`), so this file MUST exist or
    every request 500s with "View [components.layouts.app] not found".

    Kept minimal on purpose: the shell runs inside Bifrost's WebView, not a
    browser, so we skip CSRF meta, external CDNs, and service worker glue.

    CSS loading strategy:
      - If a Vite-built manifest exists (from `npm run build` on the build
        server), use @vite so the compiled/hashed bundle wins.
      - Otherwise inline the raw resources/css/app.css directly into the
        page head. Bifrost's mobile pipeline doesn't run `npm run build` on
        device, so this is the common path.
      - Per-block dist/ assets live at /blocks/{slug}/main.css and are
        loaded ad-hoc by the blocks that ship them.
--}}
@php
    $hasViteManifest = file_exists(public_path('build/manifest.json'))
        || file_exists(public_path('build/.vite/manifest.json'));
    $inlineCssPath   = resource_path('css/app.css');
    $inlineCss       = (! $hasViteManifest && is_file($inlineCssPath))
        ? (string) @file_get_contents($inlineCssPath)
        : '';

    // Expose publisher branding as CSS variables so the base stylesheet
    // inherits the app's primary/accent/background colors from the manifest.
    $branding        = data_get($manifest ?? [], 'branding', []);
    $brandingVars    = array_filter([
        '--mua-color-primary'    => $branding['primary_color']    ?? null,
        '--mua-color-accent'     => $branding['accent_color']     ?? null,
        '--mua-color-background' => $branding['background_color'] ?? null,
    ]);
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
    @elseif ($inlineCss !== '')
        <style id="mua-base-styles">{!! $inlineCss !!}</style>
    @endif

    @if (! empty($brandingVars))
        <style id="mua-branding-vars">:root {
            @foreach ($brandingVars as $prop => $value)
                {{ $prop }}: {{ $value }};
            @endforeach
        }</style>
    @endif

    @livewireStyles
</head>
<body>
    {{ $slot }}

    @livewireScripts
</body>
</html>
