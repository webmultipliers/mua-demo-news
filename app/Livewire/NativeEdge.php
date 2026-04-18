<?php

declare(strict_types=1);

namespace App\Livewire;

use Livewire\Component;
use Native\Mobile\Attributes\OnNative;
use Native\Mobile\Events\Biometric\Completed as BiometricCompleted;
use Native\Mobile\Events\Camera\PhotoTaken;
use Native\Mobile\Events\Scanner\CodeScanned;
use Native\Mobile\Facades\Biometrics;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\Camera;
use Native\Mobile\Facades\Scanner;
use Native\Mobile\Facades\Share;

/**
 * The shell's catch-all screen renderer.
 *
 * Reads `storage/app/mua-manifest.json` produced by the pub's BuildAssembler,
 * resolves the screen matching the current URL path, and hands the block
 * tree to the Blade template for rendering.
 *
 * Native capability triggers (`native-action` blocks) call into this
 * component via `wire:click="triggerCapability(...)"`. Asynchronous native
 * events route back via `#[OnNative]` listeners below and update Livewire
 * state so the Blade view re-renders with the result.
 */
class NativeEdge extends Component
{
    public string $path = '/';

    /** @var array<string, mixed>|null */
    public ?array $screen = null;

    /** @var array<string, mixed>|null */
    public ?array $manifest = null;

    public string $title = '';

    /** @var array<int, array<string, mixed>> */
    public array $navTabs = [];

    /** @var array<int, array<string, mixed>> */
    public array $drawerScreens = [];

    public string $message = '';

    /**
     * Biometric auth-gate state keyed by gate id. An auth-gate block hides
     * its inner blocks until `triggerAuthGate($id)` completes with success.
     *
     * @var array<string, bool>
     */
    public array $gates = [];

    /**
     * Last native callback payload keyed by capability, so native-action
     * blocks can display e.g. the path of the photo just taken.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $lastCallback = [];

    /**
     * Route context (post / term / author / search) for the current URL.
     * Populated from the pub's /resolve-deeplink endpoint when the URL
     * doesn't exact-match a manifest screen. Context-bound blocks
     * (`post-title`, `post-featured-image`, …) read from here.
     *
     * @var array<string, mixed>|null
     */
    public ?array $context = null;

    public function mount(string $any = ''): void
    {
        // Preserve the full request URI (path + query string) because
        // /search?q=foo needs the query to reach DeeplinkResolver. The
        // Livewire router only forwards `$any` from the path regex, so
        // anything behind `?` would otherwise be lost.
        $query      = \Illuminate\Support\Facades\Request::getQueryString();
        $basePath   = '/' . ltrim($any, '/');
        $this->path = $query !== null && $query !== '' ? $basePath . '?' . $query : $basePath;
        $this->loadManifest();
    }

    protected function loadManifest(): void
    {
        // Bifrost's mobile runtime may not extract `storage/app/` from the
        // APK assets (storage is treated as runtime-writable and can start
        // empty on first boot). Fall back to a copy at `base_path()` that
        // is guaranteed to live alongside the extracted app, and self-heal
        // the storage copy on the first successful read.
        [$manifestPath, $sigPath] = $this->resolveManifestPaths();

        if ($manifestPath === null) {
            $this->message = 'Manifest not found. Project a build from the publisher and redeploy this shell.';
            return;
        }

        $raw = file_get_contents($manifestPath);
        if ($raw === false) {
            $this->message = 'Could not read manifest file.';
            return;
        }

        $this->persistManifestToStorage($raw, $sigPath);

        // HMAC verification (fail-closed when key is configured). The pub's
        // BuildAssembler signs the exact bytes it wrote; we HMAC those same
        // bytes with MUA_APPKEY and reject tampered builds before any screen
        // data is trusted. Skipped only when MUA_APPKEY is absent (local dev).
        $appKey = env('MUA_APPKEY') ? trim((string) env('MUA_APPKEY')) : '';
        if ($appKey !== '') {
            if (! file_exists($sigPath)) {
                $this->message = 'Manifest signature missing. Refusing to render unsigned build.';
                return;
            }
            $providedSig = trim((string) file_get_contents($sigPath));
            $computedSig = hash_hmac('sha256', $raw, $appKey);
            if (! hash_equals($computedSig, $providedSig)) {
                $this->message = 'Manifest signature mismatch. Refusing to render tampered build.';
                return;
            }
        }

        $manifest = json_decode($raw, true);
        if (! is_array($manifest)) {
            $this->message = 'Manifest is not valid JSON.';
            return;
        }

        $this->manifest       = $manifest;
        $this->title          = (string) ($manifest['branding']['name'] ?? config('app.name', 'App'));
        $this->navTabs        = $this->resolveNavTabs($manifest);
        $this->drawerScreens  = $this->resolveDrawerScreens($manifest);
        $this->screen         = $this->resolveScreen($manifest, $this->path);

        // If the path doesn't exact-match a screen, ask the pub to resolve
        // it — handles /article/{slug}, /category/{slug}, /author/{slug},
        // and any publisher-defined `_mua_deeplink_path` templates. The
        // pub returns a (screen_id, context) pair; we swap in that screen
        // and surface the context so post-* blocks can read it.
        if (! $this->screen) {
            $resolved = $this->resolveDeeplink($this->path);
            if ($resolved !== null) {
                $screenId      = (string) ($resolved['screen_id'] ?? '');
                $this->context = \is_array($resolved['context'] ?? null) ? $resolved['context'] : null;
                $this->screen  = $this->findScreenById($manifest, $screenId);
            }
        }

        // If the resolved route carries a post context, log the visit.
        // Fire-and-forget: failures don't surface to the user; the next
        // online tick retries implicitly via PubClient's SWR cache.
        $contextPostId = (int) \Illuminate\Support\Arr::get($this->context, 'post.id', 0);
        if ($contextPostId > 0) {
            try {
                app(\App\Services\Persistence::class)->recordVisit(
                    $contextPostId,
                    (string) \Illuminate\Support\Arr::get($this->context, 'post.post_type', 'post'),
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::info('recordVisit skipped', ['error' => $e->getMessage()]);
            }
        }

        if (! $this->screen) {
            $this->message = 'No screen configured for path ' . $this->path;
        }
    }

    /**
     * POST the current path to the pub's /resolve-deeplink endpoint.
     * Returns null on any failure; callers fall through to the "no screen
     * configured" message so a momentary network blip doesn't look like
     * the app is broken.
     *
     * @return array<string, mixed>|null
     */
    protected function resolveDeeplink(string $path): ?array
    {
        $endpoints = $this->manifest['endpoints'] ?? [];
        $url       = is_array($endpoints) && isset($endpoints['resolve_deeplink'])
            ? (string) $endpoints['resolve_deeplink']
            : '';
        $appKey    = (string) env('MUA_APPKEY', '');
        if ($url === '' || $appKey === '') {
            return null;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $appKey,
                ])
                ->timeout(8)
                ->post($url, ['path' => $path]);
            if (! $response->successful()) {
                return null;
            }
            $body = $response->json();
            return is_array($body) ? $body : null;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('resolve-deeplink failed', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Look up a screen by its slug/id in the already-loaded manifest.
     *
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>|null
     */
    protected function findScreenById(array $manifest, string $screenId): ?array
    {
        if ($screenId === '') {
            return null;
        }
        $screens = $manifest['screens'] ?? [];
        if (! \is_array($screens)) {
            return null;
        }
        foreach ($screens as $screen) {
            if (! \is_array($screen)) {
                continue;
            }
            $candidate = (string) ($screen['id'] ?? $screen['slug'] ?? '');
            if ($candidate === $screenId) {
                return $screen;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<int, array<string, mixed>>
     */
    protected function resolveNavTabs(array $manifest): array
    {
        $nav = $manifest['navigation'] ?? [];
        $tabs = is_array($nav) && isset($nav['tabs']) && is_array($nav['tabs']) ? $nav['tabs'] : [];
        return array_values(array_filter($tabs, 'is_array'));
    }

    /**
     * All screens to list in the side-nav drawer. Defaults to every screen
     * in the manifest so the hamburger shows a full map of the app —
     * publishers who want a curated drawer can override via the
     * `navigation.drawer` manifest key.
     *
     * @param array<string, mixed> $manifest
     * @return array<int, array<string, mixed>>
     */
    protected function resolveDrawerScreens(array $manifest): array
    {
        $nav = $manifest['navigation'] ?? [];
        if (is_array($nav) && isset($nav['drawer']) && is_array($nav['drawer'])) {
            return array_values(array_filter($nav['drawer'], 'is_array'));
        }

        $screens = $manifest['screens'] ?? [];
        if (! is_array($screens)) {
            return [];
        }
        return array_values(array_filter(
            array_map(
                static fn ($s) => is_array($s) ? [
                    'title' => (string) ($s['title'] ?? ''),
                    'path'  => (string) ($s['path']  ?? '/'),
                    'icon'  => (string) ($s['icon']  ?? 'document'),
                    'id'    => $s['id'] ?? null,
                ] : null,
                $screens,
            ),
        ));
    }

    /**
     * Pick the first readable pair of (manifest, signature) files from the
     * candidate locations. Storage path wins when populated; base_path is
     * the bundle-safe fallback written by BuildAssembler on every Ship It.
     *
     * @return array{0: ?string, 1: ?string} manifest path, sig path (or nulls)
     */
    protected function resolveManifestPaths(): array
    {
        $candidates = [
            [storage_path('app/mua-manifest.json'), storage_path('app/mua-manifest.sig')],
            [base_path('mua-manifest.json'),         base_path('mua-manifest.sig')],
        ];

        foreach ($candidates as [$manifestPath, $sigPath]) {
            if (is_file($manifestPath)) {
                return [$manifestPath, $sigPath];
            }
        }
        return [null, null];
    }

    /**
     * If the manifest came from the bundle fallback, copy it (and the sig)
     * into the storage path so subsequent reads are fast and future writes
     * (e.g. a pub-driven refresh) land in a stable location.
     */
    protected function persistManifestToStorage(string $raw, ?string $sourceSig): void
    {
        $storageManifest = storage_path('app/mua-manifest.json');
        if (is_file($storageManifest)) {
            return; // Already installed.
        }
        $storageDir = dirname($storageManifest);
        if (! is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }
        @file_put_contents($storageManifest, $raw);

        if ($sourceSig !== null && is_file($sourceSig)) {
            @copy($sourceSig, storage_path('app/mua-manifest.sig'));
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    protected function resolveScreen(array $manifest, string $path): ?array
    {
        $screens = $manifest['screens'] ?? [];
        if (! is_array($screens)) {
            return null;
        }

        // Strip query string for path matching — `/about?ref=abc` should
        // still match a screen whose path is `/about`. Query strings are
        // only relevant for deeplink resolution (/search?q=...).
        $pathOnly = strtok($path, '?');
        $target   = rtrim((string) $pathOnly, '/') ?: '/';

        foreach ($screens as $screen) {
            if (! is_array($screen)) {
                continue;
            }
            $screenPath = rtrim((string) ($screen['path'] ?? ''), '/') ?: '/';
            if ($screenPath === $target) {
                return $screen;
            }
        }

        // Fall back to the first screen if nothing matched; avoids a blank
        // shell when the publisher hasn't configured a root path.
        foreach ($screens as $screen) {
            if (is_array($screen)) {
                return $screen;
            }
        }

        return null;
    }

    // --- Capability triggers (called from native-action blocks) --------

    public function triggerCapability(string $capability, string $id = '', string $url = ''): void
    {
        switch ($capability) {
            case 'browser':
                if ($url !== '') {
                    Browser::open($url);
                }
                return;

            case 'camera':
                Camera::getPhoto();
                return;

            case 'scanner':
                Scanner::scan();
                return;

            case 'share':
                if ($url !== '') {
                    Share::url($url);
                }
                return;
        }
    }

    public function triggerAuthGate(string $gateId): void
    {
        $this->gates[$gateId] = false;
        Biometrics::prompt('Unlock ' . $gateId);
    }

    /**
     * Pull-to-refresh handler. Broadcasts `block-refresh` so every nested
     * <livewire:dynamic-block> on this screen drops its PubClient cache
     * entry and re-fetches. Wired to the native gesture in the blade layer
     * (or to a manual refresh button until the gesture lands).
     */
    public function triggerRefresh(): void
    {
        // Laravel Livewire 3 broadcast: fires on every mounted component
        // that declares `#[On('block-refresh')]`.
        $this->dispatch('block-refresh');
    }

    // --- Native event listeners (async completion callbacks) -----------

    #[OnNative(PhotoTaken::class)]
    public function onPhotoTaken(string $path, string $mimeType = 'image/jpeg', ?string $id = null): void
    {
        $this->lastCallback['camera'] = [
            'path'     => $path,
            'mimeType' => $mimeType,
            'id'       => $id,
        ];
    }

    #[OnNative(CodeScanned::class)]
    public function onCodeScanned(string $data, string $format, ?string $id = null): void
    {
        $this->lastCallback['scanner'] = [
            'data'   => $data,
            'format' => $format,
            'id'     => $id,
        ];
    }

    #[OnNative(BiometricCompleted::class)]
    public function onBiometricCompleted(bool $success, ?string $id = null): void
    {
        $this->lastCallback['biometrics'] = [
            'success' => $success,
            'id'      => $id,
        ];
        if ($id !== null && $id !== '') {
            $this->gates[$id] = $success;
        }
    }

    public function render()
    {
        // Collect only the CSS/JS for blocks actually present on the
        // currently-rendering screen. BlockAssetCollector reads per-block
        // assets that BuildAssembler projected from Blockstudio's _dist/
        // at publish time, so the bytes inlined below are the same bytes
        // the WP editor + public site use.
        $collector = new \App\Support\BlockAssetCollector(
            $this->screen['block_tree'] ?? null,
        );

        // When the route resolved a detail context (post / term / author),
        // prefer that object's title over the manifest's branding name so
        // the tab / status bar reflects what the user is actually reading.
        $effectiveTitle = (string) (data_get($this->context, 'post.title') ?: $this->title);

        return view('livewire.native-edge')
            ->layoutData([
                'title'             => $effectiveTitle,
                'manifest'          => $this->manifest,
                'context'           => $this->context,
                'inlineBlockCss'    => $collector->inlineCss(),
                'inlineBlockJs'     => $collector->inlineJs(),
                'presentBlockSlugs' => $collector->slugs(),
            ]);
    }
}
