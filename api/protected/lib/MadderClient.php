<?php

declare(strict_types=1);

/**
 * Reverse-proxy client for a `madder serve` HTTP backend (MADDER_BASE_URL).
 *
 * `madder serve` streams the clear-text (decompressed, decrypted) bytes of a
 * blob in response to GET /blobs/<markl-digest>. This client fronts it so the
 * API can expose /blobs/<digest> without linking madder: a request is
 * forwarded, and the upstream status, content-type, and body are returned for
 * the route (ApiRouter) to relay, with CORS added by ApiResponse.
 *
 * Serving clear text is a deliberate first milestone. Serving ebox/age
 * ciphertext for client-side (wasm) decryption — piggy to decrypt, madder to
 * decompress — is a planned future step, not this class.
 */
class MadderClient
{
    private ?string $baseUrl;
    private int $timeout;

    public function __construct(?string $baseUrl, int $timeout = 5)
    {
        $baseUrl = is_string($baseUrl) ? trim($baseUrl) : '';
        $this->baseUrl = $baseUrl === '' ? null : rtrim($baseUrl, '/');
        $this->timeout = $timeout;
    }

    /**
     * Whether a backend is configured. When false the route answers 503
     * (genuinely unavailable, not missing) — mirroring the og-image guard.
     */
    public function isConfigured(): bool
    {
        return $this->baseUrl !== null;
    }

    /**
     * Validate a markl-id digest: "<hash-type>-<blech32>" in the lowercase
     * alphanumeric alphabet madder emits (e.g. blake2b256-c5xg...). Rejects
     * path separators, query strings, and anything else that could escape the
     * single /blobs/<digest> segment, before we ever touch the network.
     */
    public static function isValidDigest(string $digest): bool
    {
        return $digest !== ''
            && strlen($digest) <= 200
            && preg_match('~^[a-z0-9]+-[a-z0-9]+$~', $digest) === 1;
    }

    /**
     * Fetch a blob by digest from the madder backend.
     *
     * @return array{status: int, contentType: string, body: string}|null
     *   null signals the backend was unreachable (connection refused, DNS,
     *   timeout); a reached backend returns its status verbatim (incl. 404),
     *   so the caller can distinguish "no backend" from "backend says no".
     */
    public function fetchBlob(string $digest): ?array
    {
        if ($this->baseUrl === null) {
            return null;
        }

        $url = "{$this->baseUrl}/blobs/" . rawurlencode($digest);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeout,
                // Return 4xx/5xx bodies instead of false so the route can
                // relay the upstream status rather than masking it as a 502.
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            return null;
        }

        // $http_response_header is populated by the HTTP stream wrapper into
        // the local scope once the request completes.
        $headers = $http_response_header ?? [];

        return [
            'status' => $this->parseStatus($headers),
            'contentType' => $this->parseContentType($headers),
            'body' => $body,
        ];
    }

    /** @param string[] $headers */
    private function parseStatus(array $headers): int
    {
        // The status line is the first header, e.g. "HTTP/1.1 200 OK".
        if (isset($headers[0]) && preg_match('~^HTTP/\S+\s+(\d{3})~', $headers[0], $m)) {
            return (int) $m[1];
        }

        return 502;
    }

    /** @param string[] $headers */
    private function parseContentType(array $headers): string
    {
        foreach ($headers as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                return trim(substr($header, strlen('Content-Type:')));
            }
        }

        return 'application/octet-stream';
    }
}
