<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Support\PubClient;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Runtime wrapper for any block whose `native.json` declares a
 * `dataSource`. Fetches once on mount, re-renders the block's regular
 * mobile.blade.php template with the fetched payload bound to
 * `$data[<bind>]` (default bind name: `items`).
 *
 * Instance-level vs. screen-level caching:
 *   - `PubClient` owns the HTTP + cache layer and implements SWR.
 *   - This component is a thin Livewire shell — just orchestrates the
 *     fetch, renders, and subscribes to the `block-refresh` broadcast
 *     so pull-to-refresh updates every DynamicBlock on the screen at once.
 */
class DynamicBlock extends Component
{
    /** The WP block slug, e.g. "article-list". */
    public string $slug = '';

    /** @var array<string, mixed> Attributes straight from the manifest. */
    public array $attributes = [];

    /** @var array<string, mixed>|null Route context (post/term/author) if any. */
    public ?array $context = null;

    /** @var array<string, mixed>|null The fetched body (e.g. `{items, pagination, app}`). */
    public ?array $data = null;

    public bool $stale = false;

    public bool $loading = true;

    public function mount(string $slug, array $attributes = [], ?array $context = null): void
    {
        $this->slug       = $slug;
        $this->attributes = $attributes;
        $this->context    = $context;
        $this->hydrate();
    }

    /**
     * Broadcast listener: NativeEdge dispatches `block-refresh` when the
     * user pulls to refresh. We invalidate this block's PubClient cache
     * entry so the next hydrate makes a fresh request.
     */
    #[On('block-refresh')]
    public function refresh(): void
    {
        $descriptor = $this->resolveDataSource();
        if ($descriptor === null) {
            return;
        }
        app(PubClient::class)->invalidate(
            (string) ($descriptor['endpoint'] ?? ''),
            $this->resolvedParams($descriptor['params'] ?? []),
        );
        $this->hydrate();
    }

    private function hydrate(): void
    {
        $descriptor = $this->resolveDataSource();
        if ($descriptor === null) {
            $this->loading = false;
            return;
        }

        $result = app(PubClient::class)->query(
            (string) ($descriptor['endpoint'] ?? 'content'),
            $this->resolvedParams($descriptor['params'] ?? []),
            (int) ($descriptor['ttl'] ?? 300),
        );

        $this->data    = is_array($result['data']) ? $result['data'] : null;
        $this->stale   = (bool) $result['stale'];
        $this->loading = false;
    }

    /**
     * Read the block's own `native.json` from its public/blocks/{slug}
     * location (projected by BuildAssembler). Per-block file, per-block
     * contract — the shell never hard-codes which blocks are dynamic.
     *
     * @return array<string, mixed>|null
     */
    private function resolveDataSource(): ?array
    {
        if ($this->slug === '' || ! preg_match('/^[a-z0-9][a-z0-9-]*$/', $this->slug)) {
            return null;
        }
        $path = public_path('blocks/' . $this->slug . '/native.json');
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        if (! is_array($decoded)) {
            return null;
        }
        $ds = $decoded['dataSource'] ?? null;
        return is_array($ds) ? $ds : null;
    }

    /**
     * Interpolate `{{ attributes.foo }}` / `{{ context.post.id }}` tokens
     * in the dataSource param template against the current attributes +
     * context. Missing references collapse to empty string and are
     * pruned by PubClient::resolveParams().
     *
     * @param  array<string, mixed> $template
     * @return array<string, mixed>
     */
    private function resolvedParams(array $template): array
    {
        $out = [];
        foreach ($template as $key => $raw) {
            $out[(string) $key] = $this->interpolate((string) $raw);
        }
        return $out;
    }

    private function interpolate(string $template): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            function (array $m): string {
                [$scope, $field] = array_pad(explode('.', $m[1], 2), 2, '');
                return match ($scope) {
                    'attributes' => (string) ($this->attributes[$field] ?? ''),
                    'context'    => (string) data_get($this->context, $field, ''),
                    default      => '',
                };
            },
            $template,
        );
    }

    /**
     * Render the block's canonical `mobile.blade.php` (projected to
     * `resources/views/components/mustuse/{slug}.blade.php`) wrapped in
     * this component's `data-bound` template. The child template is
     * included via a Blade anonymous component so authors don't write
     * Livewire-flavored templates by accident.
     */
    public function render()
    {
        return view('livewire.dynamic-block', [
            'slug'       => $this->slug,
            'attributes' => $this->attributes,
            'context'    => $this->context,
            'data'       => $this->data,
            'stale'      => $this->stale,
            'loading'    => $this->loading,
        ]);
    }
}
