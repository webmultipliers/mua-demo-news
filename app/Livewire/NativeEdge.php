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
        // Preserve the query string — /search?q=… needs it for the
        // DeeplinkResolver; the Livewire router only forwards the path.
        $query      = \Illuminate\Support\Facades\Request::getQueryString();
        $basePath   = '/' . ltrim($any, '/');
        $this->path = $query !== null && $query !== '' ? $basePath . '?' . $query : $basePath;
        $this->loadManifest();
    }

    protected function loadManifest(): void
    {
        $manifestPath = storage_path('app/mua-manifest.json');
        $sigPath      = storage_path('app/mua-manifest.sig');

        if (! is_file($manifestPath)) {
            $this->message = 'Manifest not found. Project a build from the publisher and redeploy this shell.';
            return;
        }

        $raw = file_get_contents($manifestPath);
        if ($raw === false) {
            $this->message = 'Could not read manifest file.';
            return;
        }

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

        // Fall back to the pub for anything that isn't an exact-match
        // screen (e.g. /article/{slug}). The resolver returns
        // {screen_id, context} and we swap the matching template screen.
        if (! $this->screen) {
            $resolved = $this->resolveDeeplink($this->path);
            if ($resolved !== null) {
                $screenId      = (string) ($resolved['screen_id'] ?? '');
                $this->context = \is_array($resolved['context'] ?? null) ? $resolved['context'] : null;
                $this->screen  = $this->findScreenById($manifest, $screenId);
            }
        }

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
     * Null on any failure — callers fall through to the "no screen
     * configured" message so a transient network blip reads as a benign
     * missing-route rather than an error dialog.
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
        $bottomNav = is_array($nav) && isset($nav['bottom_nav']) && is_array($nav['bottom_nav'])
            ? $nav['bottom_nav']
            : [];
        return array_values(array_filter($bottomNav, 'is_array'));
    }

    /**
     * Drawer entries come from `navigation.drawer` — a tree projected by
     * the publisher from every published screen. Sibling order follows
     * the screen's menu_order; children are preserved so the blade layer
     * can render nested sections.
     *
     * @param array<string, mixed> $manifest
     * @return array<int, array<string, mixed>>
     */
    protected function resolveDrawerScreens(array $manifest): array
    {
        $nav = $manifest['navigation'] ?? [];
        if (! is_array($nav) || ! isset($nav['drawer']) || ! is_array($nav['drawer'])) {
            return [];
        }
        return array_values(array_filter($nav['drawer'], 'is_array'));
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

        if ($target === '/') {
            foreach ($screens as $screen) {
                if (is_array($screen) && ! empty($screen['is_home'])) {
                    return $screen;
                }
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
     * Fired by pull-to-refresh. Every DynamicBlock and QueryLoop on the
     * screen listens for `block-refresh` and drops its PubClient cache
     * entry in response.
     */
    public function triggerRefresh(): void
    {
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
        $collector = new \App\Support\BlockAssetCollector(
            $this->screen['block_tree'] ?? null,
        );

        // Use the resolved post title for the window title on detail
        // screens so the status bar reflects what's being read.
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
