<?php

declare(strict_types=1);

namespace Card;

/**
 * Producer for an object's OG image: ties CardRenderer (data → card HTML+CSS)
 * to Html2Image (HTML+CSS → rasterized hcti.io URL) and owns the request-time
 * content-hash cache that sits between them.
 *
 * The cache key is the md5 of the rendered html+css, so any card-content change
 * yields a new key and a fresh render — the previous entry simply orphans
 * rather than needing invalidation. The hcti.io call lives behind the protected
 * renderImageUrl() seam (mirroring Html2Image::post) so tests exercise the
 * cache-miss path without touching the network.
 */
class OgImage
{
    public function __construct(
        private CardRenderer $renderer,
        private string $apiKey,
        private string $cacheDir,
    ) {
    }

    /**
     * Return the hcti.io image URL for an object's card, generating + caching on
     * first request and serving the cached URL thereafter. Cache key = content
     * hash of the rendered html+css, so a card-content change yields a new key
     * (the old entry simply orphans).
     *
     * @param array<string,mixed> $data
     */
    public function urlFor(string $type, array $data): string
    {
        $html = $this->renderer->renderCard($type, $data);
        $css = $this->renderer->cardCss();
        $key = md5($html . $css);
        $cache = "{$this->cacheDir}/og-image-{$key}";

        if (is_file($cache)) {
            return (string) file_get_contents($cache);
        }

        $url = $this->renderImageUrl($html, $css);

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }

        // temp-file + rename so a concurrent reader never sees a partial write
        $tmp = "{$cache}." . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $url) !== false) {
            @rename($tmp, $cache);
        }

        return $url;
    }

    /**
     * Seam: render the card image via hcti.io. Overridden in tests so the
     * cache-miss path is exercised without the network.
     */
    protected function renderImageUrl(string $html, string $css): string
    {
        return (new Html2Image($html, $css, $this->apiKey))->getImageUrl();
    }
}
