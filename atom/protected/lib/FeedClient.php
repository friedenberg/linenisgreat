<?php

declare(strict_types=1);

/**
 * Fetches a collection's data from the JSON API (the same envelope ApiClient
 * consumes on the frontend) so the feed app stays stateless — it owns no data,
 * it just reshapes the API's into Atom/RSS. TTL-cached to the writable tmp dir,
 * with a stale-copy fallback on upstream failure, mirroring ApiClient.
 */
class FeedClient
{
    private string $baseUrl;
    private int $cacheTtl;

    /** Opaque API-response cache TTL: 1 hour. Override via API_CACHE_TTL. */
    private const DEFAULT_CACHE_TTL = 3600;

    public function __construct()
    {
        $this->baseUrl = rtrim(
            getenv('API_BASE_URL') ?: 'https://api.linenisgreat.com',
            '/',
        );
        $ttl = getenv('API_CACHE_TTL');
        $this->cacheTtl = ($ttl === false || $ttl === '') ? self::DEFAULT_CACHE_TTL : (int) $ttl;
    }

    /**
     * Return the decoded `data` for a collection, or [] when the API is
     * unavailable and nothing is cached (so the feed renders empty, not 500).
     *
     * @return array<int|string,mixed>
     */
    public function getCollection(string $type): array
    {
        $url = "{$this->baseUrl}/{$type}";
        $response = $this->fetchCached($url);

        if ($response === false) {
            error_log("FeedClient: API unavailable, serving empty feed from: {$url}");
            return [];
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
            error_log("FeedClient: invalid API response from: {$url}");
            return [];
        }

        return $decoded['data'];
    }

    private function fetchCached(string $url): string|false
    {
        $dir = __DIR__ . '/../../tmp';
        $path = "{$dir}/feed-cache-" . md5($url);

        if (
            $this->cacheTtl > 0
            && is_file($path)
            && (time() - filemtime($path)) < $this->cacheTtl
        ) {
            $cached = @file_get_contents($path);
            if ($cached !== false) {
                return $cached;
            }
        }

        $response = @file_get_contents($url);

        if ($response !== false) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $tmp = "{$path}." . getmypid() . ".tmp";
            if (@file_put_contents($tmp, $response) !== false) {
                @rename($tmp, $path);
            }
            return $response;
        }

        if (is_file($path)) {
            return @file_get_contents($path);
        }

        return false;
    }
}
