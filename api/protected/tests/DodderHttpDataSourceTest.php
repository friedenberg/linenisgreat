<?php

declare(strict_types=1);

// Hermetic TAP check for DodderHttpDataSource: overrides the network
// seam (httpGet) with canned dodder `show -format json` responses so
// the mapping logic — collection re-keying, one-element /objects
// unwrapping, percent-encoding normalization of slash-bearing zettel
// ids, markdown rendering, and graceful empties — is exercised without
// a live `dodder serve`. Network-free and deterministic.
//
// league/commonmark (for markdown partials) is loaded from the api's
// own vendor; the class under test is required directly.

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/lib/DataSource.php';
require dirname(__DIR__) . '/lib/DodderHttpDataSource.php';

/**
 * Records requested URLs and replays canned bodies keyed by a substring
 * match, so assertions can both check the response mapping and the
 * exact URL/encoding the source built.
 */
final class FakeDodderHttpDataSource extends DodderHttpDataSource
{
    /** @var array<int,string> */
    public array $requested = [];

    /** @var array<string,?string> url-substring => body */
    private array $responses;

    public function __construct(string $baseUrl, array $queries, array $responses)
    {
        parent::__construct($baseUrl, $queries);
        $this->responses = $responses;
    }

    protected function httpGet(string $url, array $headers = []): ?string
    {
        $this->requested[] = $url;

        foreach ($this->responses as $needle => $body) {
            if (str_contains($url, $needle)) {
                return $body;
            }
        }

        return null;
    }
}

$tests = [];

// --- getCollection re-keys an array of objects by object-id ---------
$queryBody = json_encode([
    ['object-id' => 'three/seis', 'type' => '!md', 'description' => 'Hello', 'tags' => ['t1']],
    ['object-id' => 'five/tres', 'type' => '!md', 'description' => 'World', 'tags' => ['t2']],
]);

$ds = new FakeDodderHttpDataSource(
    'http://dodder',
    ['objects' => ':z'],
    ['/query/inventory_list/' => $queryBody],
);

$col = $ds->getCollection('objects');
$tests[] = ['getCollection returns one entry per object', count($col) === 2];
$tests[] = ['getCollection re-keys by object-id', isset($col['three/seis'], $col['five/tres'])];
$tests[] = ['getCollection preserves item fields', ($col['three/seis']['description'] ?? null) === 'Hello'];
$tests[] = ['getCollection query is percent-encoded', str_contains($ds->requested[0], '%3Az')];

// --- getCollection caches (one HTTP call for repeated reads) --------
$before = count($ds->requested);
$ds->getCollection('objects');
$tests[] = ['getCollection is cached within a request', count($ds->requested) === $before];

// --- unmapped type resolves to an empty collection, no HTTP ---------
$reqBefore = count($ds->requested);
$tests[] = ['unmapped collection type is empty', $ds->getCollection('yoga') === []];
$tests[] = ['unmapped collection type makes no request', count($ds->requested) === $reqBefore];

// --- getItem unwraps the one-element /objects array -----------------
$itemBody = json_encode([
    ['object-id' => 'three/seis', 'type' => '!md', 'description' => 'Hello', 'blob-string' => "# Title\n", 'blob-id' => 'blake2b256-abc'],
]);

$ds2 = new FakeDodderHttpDataSource(
    'http://dodder',
    ['objects' => ':z'],
    ['/objects/' => $itemBody],
);

$item = $ds2->getItem('objects', 'three/seis');
$tests[] = ['getItem unwraps the one-element array', is_array($item) && ($item['description'] ?? null) === 'Hello'];
$tests[] = ['getItem requests blob_string=true', str_contains($ds2->requested[0], 'blob_string=true')];

// --- slash-bearing ids are encoded exactly once --------------------
$tests[] = [
    'getItem encodes a raw slash id once (three%2Fseis)',
    str_contains($ds2->requested[0], '/objects/three%2Fseis?'),
];

$ds2b = new FakeDodderHttpDataSource('http://dodder', ['objects' => ':z'], ['/objects/' => $itemBody]);
$ds2b->getItem('objects', 'three%2Fseis'); // already-encoded id
$tests[] = [
    'getItem normalizes an already-encoded id (no double-encoding)',
    str_contains($ds2b->requested[0], '/objects/three%2Fseis?')
        && !str_contains($ds2b->requested[0], '%252F'),
];

// --- getHtmlPartial renders markdown via commonmark ----------------
$html = $ds2->getHtmlPartial('objects', 'three/seis');
$tests[] = ['getHtmlPartial renders markdown to HTML', is_string($html) && str_contains($html, '<h1>Title</h1>')];

// --- non-markdown blob falls back to escaped <pre> -----------------
$rawBody = json_encode([
    ['object-id' => 'a/b', 'type' => '!toml-config', 'blob-string' => "x = <1>\n"],
]);
$ds3 = new FakeDodderHttpDataSource('http://dodder', ['objects' => ':z'], ['/objects/' => $rawBody]);
$partial = $ds3->getHtmlPartial('objects', 'a/b');
$tests[] = [
    'non-markdown blob is escaped inside <pre>',
    is_string($partial) && str_contains($partial, '<pre>') && str_contains($partial, '&lt;1&gt;'),
];

// --- missing item (no body) returns null ---------------------------
$dsMiss = new FakeDodderHttpDataSource('http://dodder', ['objects' => ':z'], []);
$tests[] = ['getItem returns null when backend has nothing', $dsMiss->getItem('objects', 'no/pe') === null];

// --- getBlob proxies raw bytes -------------------------------------
$dsBlob = new FakeDodderHttpDataSource('http://dodder', [], ['/blobs/' => 'raw-bytes']);
$tests[] = ['getBlob returns raw body', $dsBlob->getBlob('blake2b256-abc') === 'raw-bytes'];
$tests[] = ['getBlob hits the /blobs/ endpoint', str_contains($dsBlob->requested[0], '/blobs/blake2b256-abc')];

echo "TAP version 14\n";
echo '1..' . count($tests) . "\n";

$i = 0;
$fail = 0;
foreach ($tests as [$desc, $pass]) {
    $i++;
    if ($pass) {
        echo "ok {$i} - {$desc}\n";
    } else {
        echo "not ok {$i} - {$desc}\n";
        $fail = 1;
    }
}

exit($fail);
