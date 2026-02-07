<?php

declare(strict_types=1);

trait FieldMappingTrait
{
    /**
     * Extract object ID from JSON data with fallback keys.
     *
     * @param array $j
     * @param string $default
     * @return string
     */
    protected function extractObjectId(array $j, string $default = ""): string
    {
        return $j['object-id'] ?? $j['id'] ?? $j['objectId'] ?? $default;
    }

    /**
     * Normalize tags to a comma-separated string.
     * Accepts either an array or string input.
     *
     * @param mixed $tags
     * @return string
     */
    protected function normalizeTags(mixed $tags): string
    {
        if (is_array($tags)) {
            return implode(', ', $tags);
        }

        return $tags ?? "";
    }

    /**
     * Build a URL-safe path from prefix, object ID, and optional title.
     *
     * @param string $urlPrefix
     * @param string $objectId
     * @param string|null $title
     * @return string
     */
    protected function buildUrl(string $urlPrefix, string $objectId, ?string $title = null): string
    {
        if ($title !== null) {
            $encodedTitle = urlencode($title);
            return "{$urlPrefix}{$objectId}/{$encodedTitle}";
        }

        return "{$urlPrefix}{$objectId}";
    }

    /**
     * Build a search array from a search string.
     * Applies ASCII transliteration, strips HTML, and tokenizes.
     *
     * @param string $searchString
     * @return array<string, string>
     */
    protected function buildSearchArray(string $searchString): array
    {
        // Transliterate to ASCII
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $searchString);

        // Strip HTML tags
        $normalized = trim(preg_replace("/<.*?>/", " ", $normalized));

        // Normalize whitespace
        $normalized = trim(preg_replace("/\W+/", " ", $normalized));

        // Lowercase
        $normalized = strtolower($normalized);

        // Tokenize and create self-keyed array
        $tokens = preg_split("/[\W]+/", $normalized, -1, PREG_SPLIT_NO_EMPTY);

        return array_combine($tokens, $tokens);
    }

    /**
     * Extract a value with fallback keys.
     *
     * @param array $j
     * @param array $keys Keys to try in order
     * @param mixed $default
     * @return mixed
     */
    protected function extractWithFallback(array $j, array $keys, mixed $default = ""): mixed
    {
        foreach ($keys as $key) {
            if (isset($j[$key])) {
                return $j[$key];
            }
        }

        return $default;
    }
}
