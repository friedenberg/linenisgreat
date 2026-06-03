<?php

declare(strict_types=1);

/**
 * Rewrites repo-relative links and images in GitHub-rendered README HTML so they
 * resolve against the upstream GitHub repo instead of
 * linenisgreat.com/code/<name> (where they would 404).
 *
 * This is the request-time PHP equivalent of the build-time ast-grep rules in
 * the `build-code-github` recipe (justfile). DOMDocument walks <a>/<img>
 * *elements* only, so an href written as literal text inside a <pre><code> block
 * is never rewritten — it is escaped text, not an attribute — matching
 * ast-grep's AST-scoping without the fragility of a regex over raw HTML.
 *
 * HEAD in the rewritten URL resolves to the repo's default branch on GitHub.
 */
class ReadmeLinkAbsolutizer
{
    /**
     * @param string $html GitHub-rendered README HTML fragment
     * @param string $org  GitHub org/owner (e.g. "amarbel-llc")
     * @param string $repo Repository name
     * @return string the fragment with relative <a href>/<img src> absolutized
     */
    public function absolutize(string $html, string $org, string $repo): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // The <?xml encoding> prologue forces libxml to decode the bytes as
        // UTF-8 (its default is ISO-8859-1). NOIMPLIED/NODEFDTD keep the fragment
        // from being wrapped in <html><body> or gaining a doctype, so what we
        // serialize back out is the same shape GitHub handed us.
        $doc->loadHTML(
            '<?xml encoding="utf-8" ?>' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $base = "https://github.com/{$org}/{$repo}";

        // Relative href -> .../blob/HEAD/<u>; skip absolute, scheme-relative,
        // in-page anchors, and mailto: (mirrors the recipe's href not-regex).
        foreach ($doc->getElementsByTagName('a') as $anchor) {
            $href = $anchor->getAttribute('href');
            if ($this->isRelative($href, ['#', 'mailto:'])) {
                $anchor->setAttribute('href', "{$base}/blob/HEAD/{$href}");
            }
        }

        // Relative src -> .../raw/HEAD/<u>; skip absolute, scheme-relative, and
        // data: URIs (mirrors the recipe's img not-regex).
        foreach ($doc->getElementsByTagName('img') as $img) {
            $src = $img->getAttribute('src');
            if ($this->isRelative($src, ['data:'])) {
                $img->setAttribute('src', "{$base}/raw/HEAD/{$src}");
            }
        }

        $out = '';
        foreach ($doc->childNodes as $node) {
            if ($node->nodeType === XML_PI_NODE) {
                continue; // drop the <?xml encoding> prologue we injected
            }
            $out .= $doc->saveHTML($node);
        }

        return $out;
    }

    /**
     * A reference is rewritable when it is non-empty and neither already absolute
     * (has a scheme or is scheme-relative) nor one of the extra skip prefixes.
     */
    private function isRelative(string $ref, array $extraSkips): bool
    {
        if ($ref === '') {
            return false;
        }

        if (
            str_starts_with($ref, 'http:')
            || str_starts_with($ref, 'https:')
            || str_starts_with($ref, '//')
        ) {
            return false;
        }

        foreach ($extraSkips as $skip) {
            if (str_starts_with($ref, $skip)) {
                return false;
            }
        }

        return true;
    }
}
