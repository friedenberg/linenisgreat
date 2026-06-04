<?php

declare(strict_types=1);

/**
 * DataSource backed by a live `dodder serve -public` HTTP server.
 *
 * Where {@see FileDataSource} reads pre-exported JSON/HTML from
 * api/protected/data, this fetches objects and blobs straight out of
 * dodder + madder at request time:
 *
 *   - collections  -> GET /query/<list_type>/<query>   (Accept: json)
 *   - single item  -> GET /objects/<object-id>          (Accept: json)
 *   - blob bytes   -> GET /blobs/<blob-id>              (raw, from madder)
 *
 * dodder's `show -format json` shape (object-id, type, description,
 * tags, date, blob-id, blob-string) is exactly the item shape the
 * existing models and Card\CardRenderer already consume, so the
 * mapping here is essentially identity — collections are re-keyed by
 * object-id to mirror objects.json.
 *
 * The `list_type` path segment is ignored by dodder's JSON branch, so
 * a stable placeholder is used; only the query and Accept header
 * matter.
 */
class DodderHttpDataSource implements DataSource
{
    /** Placeholder list-type segment; dodder ignores it for JSON. */
    private const LIST_TYPE = 'inventory_list';

    private string $baseUrl;

    /** @var array<string,string> collection type => dodder query */
    private array $queries;

    private int $timeout;

    /** @var array<string,array> in-request collection cache */
    private array $cache = [];

    /**
     * @param array<string,string> $queries collection type => dodder query.
     *        Defaults to {@see defaultQueries}. Types absent from the map
     *        resolve to an empty collection.
     */
    public function __construct(string $baseUrl, array $queries = [], int $timeout = 10)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->queries = $queries !== [] ? $queries : self::defaultQueries();
        $this->timeout = $timeout;
    }

    /**
     * Default collection -> query map for a dodder-native site. `objects`
     * and `notes` (its alias) list public markdown zettels; `types` and
     * `tags` expose the type/tag genres.
     *
     * @return array<string,string>
     */
    public static function defaultQueries(): array
    {
        $public = getenv('DODDER_PUBLIC_QUERY');
        $public = ($public === false || $public === '') ? 'public !md' : $public;

        return [
            'objects' => $public,
            'notes' => $public,
            'types' => ':t',
            'tags' => ':e',
        ];
    }

    public function getCollection(string $type): array
    {
        if (isset($this->cache[$type])) {
            return $this->cache[$type];
        }

        $query = $this->queries[$type] ?? null;

        if ($query === null) {
            return [];
        }

        $items = $this->fetchQuery($query);

        // Re-key by object-id so the shape matches the build-time
        // objects.json (a keyed dictionary), which getItem() and the
        // frontend models expect.
        $keyed = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = $item['object-id'] ?? $item['id'] ?? $item['objectId'] ?? null;

            if ($id !== null) {
                $keyed[(string) $id] = $item;
            } else {
                $keyed[] = $item;
            }
        }

        $this->cache[$type] = $keyed;

        return $keyed;
    }

    public function getItem(string $type, string $id): ?array
    {
        // A single object, fetched with its blob body embedded so the
        // detail page and card renderer have everything they need. The
        // id may arrive already percent-encoded (the API router matches
        // against the encoded path, so a zettel id's `/` is `%2F`);
        // normalize via decode-then-encode so it is encoded exactly once
        // for dodder, whichever form we received.
        $encodedId = rawurlencode(rawurldecode($id));
        $url = $this->baseUrl . '/objects/' . $encodedId . '?blob_string=true';

        $body = $this->httpGet($url, ['Accept: application/json']);

        if ($body === null) {
            // Fall back to the cached collection (e.g. for types/tags
            // that are not addressable as a single OID over /objects).
            // Collection keys are decoded object-ids, so match on the
            // decoded form.
            $collection = $this->getCollection($type);

            return $collection[rawurldecode($id)] ?? null;
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded) || $decoded === []) {
            return null;
        }

        // /objects returns a one-element array of objects.
        $first = $decoded[0] ?? null;

        return is_array($first) ? $first : null;
    }

    public function getHtmlPartial(string $type, string $id): ?string
    {
        $item = $this->getItem($type, $id);

        if ($item === null) {
            return null;
        }

        $blob = (string) ($item['blob-string'] ?? '');

        return $this->renderBlobHtml((string) ($item['type'] ?? ''), $blob);
    }

    /**
     * Fetch a query result as an array of dodder JSON objects.
     *
     * @return array<int,array>
     */
    private function fetchQuery(string $query): array
    {
        $url = $this->baseUrl . '/query/' . self::LIST_TYPE . '/' . rawurlencode($query);

        $body = $this->httpGet($url, ['Accept: application/json']);

        if ($body === null) {
            return [];
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Render a blob body to an HTML partial. Markdown (`!md`) is rendered
     * via league/commonmark when available; otherwise (and for non-markdown
     * blobs) the body is HTML-escaped inside a <pre> so it is always safe
     * to embed.
     */
    private function renderBlobHtml(string $type, string $blob): string
    {
        $isMarkdown = $type === '' || str_contains($type, 'md');

        if ($isMarkdown && class_exists(\League\CommonMark\CommonMarkConverter::class)) {
            $converter = new \League\CommonMark\CommonMarkConverter();

            return (string) $converter->convert($blob);
        }

        return '<pre>' . htmlspecialchars($blob, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
    }

    /**
     * GET a URL, returning the body on 2xx or null on any failure
     * (network error, non-2xx). Failures are swallowed so a flaky or
     * down dodder backend degrades to "empty/absent" rather than a 500.
     *
     * Protected so hermetic tests can override the network seam with
     * canned responses (mirrors Card\Html2Image::post).
     *
     * @param array<int,string> $headers
     */
    protected function httpGet(string $url, array $headers = []): ?string
    {
        $ch = curl_init($url);

        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            // dodder signs the response body in an HTTP trailer; we
            // don't verify it (public read) and don't want trailer
            // handling to interfere with the body read.
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            return null;
        }

        return (string) $body;
    }

    /**
     * Proxy a raw blob by its markl/content digest straight from madder
     * via dodder. Returns the bytes, or null if missing/unavailable.
     */
    public function getBlob(string $blobId): ?string
    {
        return $this->httpGet($this->baseUrl . '/blobs/' . rawurlencode($blobId));
    }
}
