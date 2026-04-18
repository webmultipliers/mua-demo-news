<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Support\PubClient;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * WordPress-theme-style Query Loop on the mobile shell.
 *
 * One Livewire instance per loop — *not per item* — so a 10-item grid
 * with five context blocks per card is still 1 Livewire component, not
 * 50. Children are rendered as plain Blade with `$context` carried in,
 * which keeps per-item rendering O(ms), not O(wire-request).
 *
 * Mount flow:
 *   1. Parse the query-loop's native.json `dataSource` template against
 *      the configured attributes (postType, count, category, ...).
 *   2. Fetch via PubClient — SWR cache, 5-minute default TTL.
 *   3. Surface `items` to the blade view; iteration happens there.
 *
 * Inner-block split:
 *   Children of `<query-loop>` fall into two buckets:
 *     a. `mustuse-apps-pub/post-template` → the iteration body; its
 *        own innerBlocks repeat once per item with context.post set.
 *     b. everything else → "chrome" (header, see-all link, footer).
 *        Rendered once before/after the loop body, WITHOUT per-item
 *        context, so a section-heading above the list doesn't try to
 *        read post.title.
 */
class QueryLoop extends Component
{
    public string $slug = 'query-loop';

    /** @var array<string, mixed> */
    public array $attributes = [];

    /** @var array<int, array<string, mixed>> */
    public array $children = [];

    /** @var array<string, mixed>|null */
    public ?array $context = null;

    /** @var array<int, array<string, mixed>> */
    public array $items = [];

    public bool $loading = true;

    public bool $stale = false;

    /**
     * @param array<string, mixed>                  $attributes
     * @param array<int, array<string, mixed>>      $children
     * @param array<string, mixed>|null             $context
     */
    public function mount(array $attributes = [], array $children = [], ?array $context = null): void
    {
        $this->attributes = $attributes;
        $this->children   = $children;
        $this->context    = $context;
        $this->hydrate();
    }

    #[On('block-refresh')]
    public function refresh(): void
    {
        $descriptor = $this->dataSource();
        if ($descriptor !== null) {
            app(PubClient::class)->invalidate(
                (string) ($descriptor['endpoint'] ?? ''),
                $this->resolvedParams($descriptor['params'] ?? []),
            );
        }
        $this->hydrate();
    }

    private function hydrate(): void
    {
        $descriptor = $this->dataSource();
        if ($descriptor === null) {
            $this->loading = false;
            return;
        }
        $result = app(PubClient::class)->query(
            (string) ($descriptor['endpoint'] ?? 'content'),
            $this->resolvedParams($descriptor['params'] ?? []),
            (int) ($descriptor['ttl'] ?? 300),
        );

        $items       = \Illuminate\Support\Arr::get($result['data'], 'items', []);
        $this->items = \is_array($items) ? \array_values(\array_filter($items, 'is_array')) : [];
        $this->stale = (bool) $result['stale'];
        $this->loading = false;
    }

    /**
     * Split the children into:
     *   postTemplate:   the single post-template block (its innerBlocks
     *                   repeat once per item).
     *   chrome:         every sibling block that renders once (header /
     *                   see-all link / footer). Authors rarely add
     *                   chrome today but the contract is there so a
     *                   section-heading above a loop works without
     *                   trying to read post.title.
     *
     * @return array{postTemplate: ?array<string,mixed>, chromeBefore: list<array<string,mixed>>, chromeAfter: list<array<string,mixed>>}
     */
    public function splitChildren(): array
    {
        $postTemplate  = null;
        $chromeBefore  = [];
        $chromeAfter   = [];

        $seenTemplate = false;
        foreach ($this->children as $child) {
            if (! \is_array($child)) {
                continue;
            }
            $type = (string) ($child['type'] ?? $child['blockName'] ?? '');
            if ($type === 'mustuse-apps-pub/post-template') {
                $postTemplate = $child;
                $seenTemplate = true;
                continue;
            }
            if (! $seenTemplate) {
                $chromeBefore[] = $child;
            } else {
                $chromeAfter[] = $child;
            }
        }

        return [
            'postTemplate' => $postTemplate,
            'chromeBefore' => $chromeBefore,
            'chromeAfter'  => $chromeAfter,
        ];
    }

    /**
     * Build a per-item context object. Shape matches the pub-side
     * ContextBuilder::postContext() output so post-title et al. read
     * the same field path they do on detail screens.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public function contextForItem(array $item): array
    {
        // Start with the parent context (so screen-level fields like
        // `search.query` propagate through loops) then scope `post` to
        // the current iteration. A nested loop future can stack this.
        $base = \is_array($this->context) ? $this->context : [];
        $base['post'] = $item;
        return $base;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function dataSource(): ?array
    {
        $path = public_path('blocks/query-loop/native.json');
        if (! \is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        return \is_array($decoded) && \is_array($decoded['dataSource'] ?? null) ? $decoded['dataSource'] : null;
    }

    /**
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

    public function render()
    {
        return view('livewire.query-loop', [
            'layout' => $this->attributes['layout'] ?? 'stack',
        ]);
    }
}
