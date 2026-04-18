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

    public function mount(string $any = ''): void
    {
        $this->path = '/' . ltrim($any, '/');
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

        if (! $this->screen) {
            $this->message = 'No screen configured for path ' . $this->path;
        }
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

        $target = rtrim($path, '/') ?: '/';

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

        return view('livewire.native-edge')
            ->layoutData([
                'title'             => $this->title,
                'manifest'          => $this->manifest,
                'inlineBlockCss'    => $collector->inlineCss(),
                'inlineBlockJs'     => $collector->inlineJs(),
                'presentBlockSlugs' => $collector->slugs(),
            ]);
    }
}
