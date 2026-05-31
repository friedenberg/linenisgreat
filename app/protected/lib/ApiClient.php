<?php

declare(strict_types=1);

class ApiClient
{
    private string $endpoint;
    private string $baseUrl;
    private ?array $raw = null;
    private int $cacheTtl;

    /** Opaque API-response cache TTL: 1 hour. Override via API_CACHE_TTL (seconds; 0 disables). */
    private const DEFAULT_CACHE_TTL = 3600;

    /**
     * @param string $endpoint API endpoint name (e.g., 'objects', 'yoga', 'code')
     */
    public function __construct(string $endpoint)
    {
        $this->endpoint = $endpoint;
        $this->baseUrl = rtrim(
            getenv('API_BASE_URL') ?: 'https://api.linenisgreat.com',
            '/',
        );
        $ttl = getenv('API_CACHE_TTL');
        $this->cacheTtl = ($ttl === false || $ttl === '') ? self::DEFAULT_CACHE_TTL : (int) $ttl;
    }

    /**
     * Opaquely cache an API GET to the writable tmp dir (the cocktail-image
     * pattern; see Zettel::getImageUrl), keyed by the request URL with a TTL.
     * The cache is agnostic to what backs the API — static files today, dodder
     * later — so it carries across that swap unchanged. On an upstream failure a
     * stale cached copy is served if one exists; returns false only when there's
     * neither a fresh fetch nor any cached copy.
     */
    private function fetchCached(string $url): string|false
    {
        $dir = __DIR__ . '/../../tmp';
        $path = "{$dir}/api-cache-" . md5($url);

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

        // Miss or expired: refetch. The @ suppresses the connection warning on a
        // non-200 (callers handle false / catch the exception).
        $response = @file_get_contents($url);

        if ($response !== false) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            // Write via temp file + rename so a concurrent reader never sees a
            // half-written cache entry.
            $tmp = "{$path}." . getmypid() . ".tmp";
            if (@file_put_contents($tmp, $response) !== false) {
                @rename($tmp, $path);
            }
            return $response;
        }

        // Upstream failed: fall back to a stale cached copy if we have one.
        if (is_file($path)) {
            return @file_get_contents($path);
        }

        return false;
    }

    /**
     * @return array
     */
    public function getRaw(): array
    {
        if ($this->raw !== null) {
            return $this->raw;
        }

        $url = "{$this->baseUrl}/{$this->endpoint}";
        $response = $this->fetchCached($url);

        // Degrade gracefully instead of fatalling. A transient API failure with
        // no cached copy to fall back on — e.g. the NFSN hiccup right after a
        // web-kick, when the deploy cache-bust has just cleared the stale copy —
        // returns empty data so pages still render (empty index / description
        // fallback / no footer) rather than 500. Logged for observability and
        // memoized so one failed fetch isn't retried within the request.
        if ($response === false) {
            error_log("ApiClient: API unavailable, serving empty data from: {$url}");
            return $this->raw = [];
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded) || !isset($decoded['data'])) {
            error_log("ApiClient: invalid API response from: {$url}");
            return $this->raw = [];
        }

        return $this->raw = $decoded['data'];
    }

    /**
     * @param string $className
     * @param string|null $urlPrefix
     * @return array
     */
    public function parseCustomClass(string $className, ?string $urlPrefix = null): array
    {
        return array_map(
            function ($c) use ($className, $urlPrefix) {
                if ($urlPrefix !== null) {
                    return new $className($c, $urlPrefix);
                }

                return new $className($c);
            },
            $this->getRaw(),
        );
    }

    /**
     * @param string|null $urlPrefix
     * @param string $className
     * @return array
     */
    public function parse(?string $urlPrefix = null, string $className = 'Zettel'): array
    {
        return $this->parseCustomClass($className, $urlPrefix);
    }

    /**
     * @param string $objectId
     * @return string
     */
    public function getHtmlPartial(string $objectId): string
    {
        $url = "{$this->baseUrl}/{$this->endpoint}/{$objectId}/html";
        // Cached + warning-suppressed fetch; callers catch the exception and fall
        // back to a description-only card when a partial is genuinely missing.
        $response = $this->fetchCached($url);

        if ($response === false) {
            throw new Exception("Failed to fetch HTML partial from API: {$url}");
        }

        return $response;
    }
}
