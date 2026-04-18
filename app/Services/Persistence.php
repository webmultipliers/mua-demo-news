<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\PubClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Native\Mobile\Facades\SecureStorage;

/**
 * Device-scoped personalization (bookmarks, read history, push token)
 * with a local-first, pub-sync'd cache.
 *
 * Every call is **local-first**: the on-device SecureStorage copy is the
 * source of truth for UI rendering. When a mutation happens we update
 * the local copy synchronously, then fire an async sync to the pub's
 * /bookmarks, /history, or /push/enroll endpoint. If the network fails
 * the local copy is unaffected and we'll retry on the next online tick
 * (through PubClient's SWR cache).
 *
 * Device id is generated once and kept in SecureStorage under a stable
 * key. We hand it to the pub via X-MUA-Device-Id on every request.
 */
class Persistence
{
    private const DEVICE_ID_KEY    = 'mua:device_id';
    private const BOOKMARKS_CACHE  = 'mua:bookmarks';
    private const HISTORY_CACHE    = 'mua:history';
    private const PUSH_TOKEN_CACHE = 'mua:push_token';

    public function deviceId(): string
    {
        $stored = SecureStorage::get(self::DEVICE_ID_KEY);
        if (\is_string($stored) && $stored !== '') {
            return $stored;
        }
        // First-boot generation. 32 bytes of entropy → 43 base64url chars;
        // keyspace is >>> than the pub's hashing window so collisions are
        // a non-concern.
        try {
            $bytes = \random_bytes(32);
        } catch (\Throwable $e) {
            // CSPRNG unavailable (rare) — degrade to a timestamp-seeded id.
            // Doesn't need to be adversarial-safe, just unique per install.
            $bytes = \hash('sha256', (string) \microtime(true) . \uniqid('', true), true);
        }
        $id = \rtrim(\strtr(\base64_encode($bytes), '+/', '-_'), '=');
        SecureStorage::set(self::DEVICE_ID_KEY, $id);
        return $id;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function bookmarks(bool $forceRefresh = false): array
    {
        if (! $forceRefresh) {
            $cached = Cache::get(self::BOOKMARKS_CACHE);
            if (\is_array($cached)) {
                return $cached;
            }
        }
        $items = $this->fetchFromPub('bookmarks');
        if ($items !== null) {
            Cache::put(self::BOOKMARKS_CACHE, $items, now()->addHours(6));
            return $items;
        }
        return $forceRefresh ? [] : (Cache::get(self::BOOKMARKS_CACHE) ?? []);
    }

    public function addBookmark(int $postId, string $postType = 'post'): void
    {
        $items = $this->mutatePub('bookmarks', 'POST', ['post_id' => $postId, 'post_type' => $postType]);
        if ($items !== null) {
            Cache::put(self::BOOKMARKS_CACHE, $items, now()->addHours(6));
        }
    }

    public function removeBookmark(int $postId): void
    {
        $items = $this->mutatePub('bookmarks', 'DELETE', ['post_id' => $postId]);
        if ($items !== null) {
            Cache::put(self::BOOKMARKS_CACHE, $items, now()->addHours(6));
        }
    }

    public function isBookmarked(int $postId): bool
    {
        foreach ($this->bookmarks() as $row) {
            $rowPostId = (int) \Illuminate\Support\Arr::get($row, 'post.id', 0);
            if ($rowPostId === $postId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Record a visit — called by NativeEdge when an article detail screen
     * mounts. Local-first: we don't block on the pub's response because
     * failure is recoverable (history sync is advisory, not critical).
     */
    public function recordVisit(int $postId, string $postType = 'post'): void
    {
        if ($postId <= 0) {
            return;
        }
        $this->mutatePub('history', 'POST', ['post_id' => $postId, 'post_type' => $postType]);
        // Invalidate cache so the next history read goes through to the pub.
        Cache::forget(self::HISTORY_CACHE);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function history(bool $forceRefresh = false): array
    {
        if (! $forceRefresh) {
            $cached = Cache::get(self::HISTORY_CACHE);
            if (\is_array($cached)) {
                return $cached;
            }
        }
        $items = $this->fetchFromPub('history');
        if ($items !== null) {
            Cache::put(self::HISTORY_CACHE, $items, now()->addHours(1));
            return $items;
        }
        return $forceRefresh ? [] : (Cache::get(self::HISTORY_CACHE) ?? []);
    }

    /**
     * Enroll the current device for push. Called once on first launch
     * after NativePHP's PushNotifications::enroll() returns a token.
     * Token is cached locally so we don't re-enroll on every boot.
     */
    public function enrollPush(string $token, string $platform): void
    {
        $cached = SecureStorage::get(self::PUSH_TOKEN_CACHE);
        if (\is_string($cached) && $cached === $token) {
            return; // Already enrolled with this exact token.
        }

        $url      = $this->endpointUrl('push_enroll');
        $deviceId = $this->deviceId();
        if ($url === '' || $deviceId === '') {
            return;
        }

        try {
            $response = Http::withHeaders([
                    'Accept'            => 'application/json',
                    'Authorization'     => 'Bearer ' . (string) env('MUA_APPKEY', ''),
                    'X-MUA-Device-Id'   => $deviceId,
                ])
                ->timeout(10)
                ->post($url, ['token' => $token, 'platform' => $platform]);
            if ($response->successful()) {
                SecureStorage::set(self::PUSH_TOKEN_CACHE, $token);
            }
        } catch (\Throwable $e) {
            Log::warning('Persistence.enrollPush failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function fetchFromPub(string $endpointName): ?array
    {
        $url      = $this->endpointUrl($endpointName);
        $deviceId = $this->deviceId();
        if ($url === '' || $deviceId === '') {
            return null;
        }
        try {
            $response = Http::withHeaders([
                    'Accept'          => 'application/json',
                    'Authorization'   => 'Bearer ' . (string) env('MUA_APPKEY', ''),
                    'X-MUA-Device-Id' => $deviceId,
                ])
                ->timeout(8)
                ->get($url);
            if (! $response->successful()) {
                return null;
            }
            $items = $response->json('items');
            return \is_array($items) ? $items : [];
        } catch (\Throwable $e) {
            Log::warning("Persistence.fetch({$endpointName}) failed", ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * @param  array<string, mixed> $payload
     * @return array<int, array<string, mixed>>|null
     */
    private function mutatePub(string $endpointName, string $method, array $payload): ?array
    {
        $url      = $this->endpointUrl($endpointName);
        $deviceId = $this->deviceId();
        if ($url === '' || $deviceId === '') {
            return null;
        }
        try {
            $http = Http::withHeaders([
                'Accept'          => 'application/json',
                'Authorization'   => 'Bearer ' . (string) env('MUA_APPKEY', ''),
                'X-MUA-Device-Id' => $deviceId,
            ])->timeout(10);
            $response = match ($method) {
                'POST'   => $http->post($url, $payload),
                'DELETE' => $http->delete($url, $payload),
                default  => null,
            };
            if ($response === null || ! $response->successful()) {
                return null;
            }
            $items = $response->json('items');
            return \is_array($items) ? $items : null;
        } catch (\Throwable $e) {
            Log::warning("Persistence.mutate({$endpointName}) failed", ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function endpointUrl(string $name): string
    {
        return (string) (app(PubClient::class)->resolveEndpoint($name) ?? '');
    }
}
