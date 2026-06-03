<?php

declare(strict_types=1);

/**
 * Fetches a project's README from GitHub at request time and serves it as
 * ready-to-render HTML (relative links absolutized), TTL-cached on disk.
 *
 * This is the read-through counterpart to the build-time README snapshots that
 * `build-code-github` bakes into api/protected/data/code/<name>/index.html: it
 * lets /code/<name> reflect the live GitHub README without a build + deploy
 * cycle. It mirrors the request-time-cURL-to-GitHub pattern established by the
 * git smart-HTTP proxy (app/public/code_git_proxy.php, ADR 0002) and the
 * cache-with-stale-fallback pattern of ApiClient::fetchCached.
 *
 * GitHub's rendered HTML is the same source GitHub itself shows, so fidelity
 * (alerts, task lists, syntax highlighting) matches exactly.
 */
class GithubReadmeClient
{
    private string $org;
    private int $ttl;
    private string $cacheDir;
    private ?string $token;
    private ReadmeLinkAbsolutizer $absolutizer;

    public function __construct(
        string $org,
        int $ttl,
        string $cacheDir,
        ?string $token,
        ReadmeLinkAbsolutizer $absolutizer
    ) {
        $this->org = $org;
        $this->ttl = $ttl;
        $this->cacheDir = rtrim($cacheDir, '/');
        $this->token = ($token === '') ? null : $token;
        $this->absolutizer = $absolutizer;
    }

    /**
     * Return a project's README as ready-to-serve HTML, or null when
     * read-through is unavailable — no token configured, an invalid name, or an
     * upstream failure with no cached copy — so the caller can fall back to the
     * build-time partial or the description.
     */
    public function fetch(string $name): ?string
    {
        // No token: skip the live path entirely. This keeps CI and local dev
        // hermetic (no network) and avoids burning GitHub's unauthenticated
        // 60/hr-per-IP limit, which is shared across tenants on NFSN.
        if ($this->token === null) {
            return null;
        }

        // Same slug guard as the git proxy (code_git_proxy.php): letters,
        // digits, '.', '_', '-' only, blocking path traversal / URL injection.
        if (!preg_match('/^[\w.-]+$/', $name) || str_contains($name, '..')) {
            return null;
        }

        $path = "{$this->cacheDir}/readme-" . md5("{$this->org}/{$name}") . '.html';

        if (
            $this->ttl > 0
            && is_file($path)
            && (time() - filemtime($path)) < $this->ttl
        ) {
            $cached = @file_get_contents($path);
            if ($cached !== false) {
                return $cached;
            }
        }

        $fresh = $this->fetchUpstream($name);

        if ($fresh !== null) {
            $html = $this->absolutizer->absolutize($fresh, $this->org, $name);
            $this->store($path, $html);
            return $html;
        }

        // Upstream failed: serve a stale cached copy if we have one.
        if (is_file($path)) {
            $stale = @file_get_contents($path);
            if ($stale !== false) {
                return $stale;
            }
        }

        return null;
    }

    /**
     * The rendered-HTML media type returns GitHub's GFM-rendered README body
     * directly (not JSON-wrapped) — the same bytes `build-code-github` captures.
     */
    private function fetchUpstream(string $name): ?string
    {
        $ch = curl_init("https://api.github.com/repos/{$this->org}/{$name}/readme");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/vnd.github.html+json',
                "Authorization: Bearer {$this->token}",
                'X-GitHub-Api-Version: 2022-11-28',
                'User-Agent: linenisgreat-readme-readthrough',
            ],
        ]);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0 || $status !== 200 || !is_string($body) || $body === '') {
            error_log(
                "GithubReadmeClient: readme fetch failed for {$this->org}/{$name} "
                . "(status={$status}, curl={$errno})",
            );
            return null;
        }

        return $body;
    }

    /** Atomic write so a concurrent reader never sees a half-written entry. */
    private function store(string $path, string $html): void
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }

        $tmp = "{$path}." . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $html) !== false) {
            @rename($tmp, $path);
        }
    }
}
