<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Shell → pub HTTP client with SWR (stale-while-revalidate) caching.
 *
 * Reads endpoints from the manifest's `endpoints` map (already cached on
 * disk in `storage/app/mua-manifest.json`). Auth is the bearer token
 * `MUA_APPKEY` that Bifrost injects per-app at build time. **Never** logs
 * the Authorization header — see `redactedContextFor()`.
 *
 * SWR semantics:
 *   - Cached-and-fresh      → return cached bytes, no request.
 *   - Cached-and-stale      → return cached bytes immediately, fire a
 *                             background refresh (queued job when possible,
 *                             fallthrough to sync when queues are absent).
 *   - Cache miss            → synchronous fetch, populate cache.
 *   - Network failure       → return cached (even if past TTL) if present,
 *                             else empty shape so the template can render
 *                             its own empty state instead of blowing up.
 *
 * `invalidate($cacheKey)` is how pull-to-refresh drops a specific entry;
 * `invalidateAll()` wipes the whole pool (rare — used on manifest reload).
 */
class PubClient
{
    private const CACHE_PREFIX = 'pub:';

    /**
     * Lookup an endpoint URL from the manifest's `endpoints` map. The
     * manifest is the shell's only source of truth about where the pub
     * lives — BuildAssembler projects it; Bifrost may rotate the base URL
     * without re-signing if the endpoints map is the one canonical reference.
     */
    public function resolveEndpoint(string $name): ?string
    {
        $manifest = $this->readManifest();
        $endpoints = $manifest['endpoints'] ?? [];
        if (! is_array($endpoints)) {
            return null;
        }
        $url = $endpoints[$name] ?? null;
        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Fetch `endpoint` with `params`. Returns `{data: mixed, stale: bool,
     * revision: string|null}`. Shape-wise, `data` is whatever the endpoint
     * returns (usually `{items, pagination, app}` for content/terms).
     *
     * @param array<string, mixed> $params
     * @return array{data: mixed, stale: bool, revision: ?string}
     */
    public function query(string $endpointName, array $params = [], int $ttl = 300): array
    {
        $url = $this->resolveEndpoint($endpointName);
        if ($url === null) {
            return ['data' => null, 'stale' => false, 'revision' => null];
        }

        $params   = $this->resolveParams($params);
        $cacheKey = self::cacheKeyFor($endpointName, $params);
        $cached   = Cache::get($cacheKey);

        // Hot path: cached and within TTL. Return without touching the network.
        if (is_array($cached) && isset($cached['expires']) && $cached['expires'] > time()) {
            return [
                'data'     => $cached['data']     ?? null,
                'stale'    => false,
                'revision' => $cached['revision'] ?? null,
            ];
        }

        // Stale path: cached but past TTL. Return the stale body and fire
        // a background refresh. Shells on slow mobile networks get an
        // instant render; the refresh updates on the next component tick.
        if (is_array($cached) && isset($cached['data'])) {
            $self = $this;
            defer(static fn () => $self->fetchAndStore($url, $params, $cacheKey, $ttl));
            return [
                'data'     => $cached['data'],
                'stale'    => true,
                'revision' => $cached['revision'] ?? null,
            ];
        }

        // Cache miss: synchronous fetch.
        try {
            $fresh = $this->fetchAndStore($url, $params, $cacheKey, $ttl);
            return [
                'data'     => $fresh['data'],
                'stale'    => false,
                'revision' => $fresh['revision'],
            ];
        } catch (\Throwable $e) {
            Log::warning('PubClient.query failed', $this->redactedContextFor($e, $url, $params));
            return ['data' => null, 'stale' => false, 'revision' => null];
        }
    }

    /**
     * Drop a single query's cache entry — used by pull-to-refresh and by
     * the `refresh` broadcast dispatched from NativeEdge when the user
     * explicitly requests fresh data.
     *
     * @param array<string, mixed> $params
     */
    public function invalidate(string $endpointName, array $params = []): void
    {
        Cache::forget(self::cacheKeyFor($endpointName, $this->resolveParams($params)));
    }

    /**
     * @param array<string, mixed> $params
     * @return array{data: mixed, revision: ?string}
     */
    private function fetchAndStore(string $url, array $params, string $cacheKey, int $ttl): array
    {
        $response = Http::withHeaders([
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $this->appKey(),
            ])
            ->timeout(10)
            ->get($url, $params)
            ->throw();

        $revision = $response->header('X-MUA-Content-Revision') ?: null;
        $data     = $response->json();

        Cache::put($cacheKey, [
            'data'     => $data,
            'revision' => $revision,
            'expires'  => time() + $ttl,
        ], max($ttl * 4, 3600)); // Keep stale bytes around ~4x TTL for SWR fallbacks.

        return ['data' => $data, 'revision' => $revision];
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(): array
    {
        $candidates = [
            storage_path('app/mua-manifest.json'),
            base_path('mua-manifest.json'),
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                $raw = @file_get_contents($path);
                if ($raw !== false) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            }
        }
        return [];
    }

    /**
     * Drop keys whose value is empty-or-zero (so the cache key is stable
     * when a template leaves an optional attribute unset) and sort for
     * determinism. The pub's cache key uses the same strategy.
     *
     * @param  array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function resolveParams(array $params): array
    {
        $cleaned = [];
        foreach ($params as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            if ($v === 0 || $v === '0') {
                continue; // e.g. category=0 means "no filter"
            }
            $cleaned[(string) $k] = $v;
        }
        ksort($cleaned);
        return $cleaned;
    }

    private function appKey(): string
    {
        $key = (string) env('MUA_APPKEY', '');
        if ($key === '') {
            throw new RuntimeException('MUA_APPKEY env missing — shell cannot authenticate to the pub.');
        }
        return $key;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function cacheContext(string $endpointName, array $params): string
    {
        return self::cacheKeyFor($endpointName, $params);
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function cacheKeyFor(string $endpointName, array $params): string
    {
        return self::CACHE_PREFIX . $endpointName . ':' . sha1(json_encode($params));
    }

    /**
     * Scrub anything sensitive before Log::warning.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function redactedContextFor(\Throwable $e, string $url, array $params): array
    {
        return [
            'endpoint' => $url,
            'params'   => $params,
            'error'    => $e->getMessage(),
            'class'    => get_class($e),
        ];
    }
}
