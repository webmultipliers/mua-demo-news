{{--
    Default layout for full-page Livewire components (NativeEdge, etc).

    Livewire 3 resolves `components.layouts.app` when a route points directly
    at a Component class (see `routes/web.php`), so this file MUST exist or
    every request 500s with "View [components.layouts.app] not found".

    Kept minimal on purpose: the shell runs inside Bifrost's WebView, not a
    browser, so we skip CSRF meta, external CDNs, and service worker glue.
    Vite-built CSS/JS is loaded when the manifest is present; it's absent on
    a freshly-projected build (no `npm run build` runs on-device), in which
    case we degrade to unstyled HTML rather than crash on `@vite`.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name', 'App') }}</title>

    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('build/.vite/manifest.json')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif

    @livewireStyles
</head>
<body>
    {{ $slot }}

    @livewireScripts
</body>
</html>
