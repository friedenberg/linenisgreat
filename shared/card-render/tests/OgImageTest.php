<?php

declare(strict_types=1);

// Hermetic TAP check for Card\OgImage: ties CardRenderer (real, package
// templates) to a stubbed render seam so the content-hash cache is exercised
// without touching the network. Subclasses OgImage to override renderImageUrl()
// with a call-counter + canned URL, then asserts the three cache behaviours:
//   1. cache miss   → renders once, returns the URL, writes the cache file.
//   2. cache hit    → second identical request short-circuits (count stays 1).
//   3. content change → a different card produces a new key (count increments).
//
// The package ships no vendor of its own, so Mustache (CardRenderer's only
// third-party dependency) is loaded from the app's vendor; the package classes
// are required directly, mirroring CardRendererTest / Html2ImageTest. The cache
// dir is a unique temp dir, removed at the end so the test leaves no residue.

require dirname(__DIR__, 3) . '/app/protected/vendor/autoload.php';
require __DIR__ . '/../src/Html2Image.php';
require __DIR__ . '/../src/CardRenderer.php';
require __DIR__ . '/../src/OgImage.php';

/**
 * Test double: counts how often the (network) render seam fires and returns a
 * deterministic, html+css-derived URL instead of POSTing to hcti.io. The URL
 * embeds an md5 of the inputs so distinct cards yield distinct URLs, making the
 * "content change" assertion meaningful beyond the call-count.
 */
final class CountingOgImage extends \Card\OgImage
{
    public int $renderCount = 0;

    protected function renderImageUrl(string $html, string $css): string
    {
        $this->renderCount++;
        return 'https://hcti.io/v1/image/' . md5($html . $css) . '.png';
    }
}

$cacheDir = sys_get_temp_dir() . '/card-render-ogimage-test-' . getmypid() . '-' . bin2hex(random_bytes(4));

$cleanup = static function () use ($cacheDir): void {
    if (!is_dir($cacheDir)) {
        return;
    }
    foreach (glob($cacheDir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($cacheDir);
};

// Representative card data (a real cocktail) so html+css fed to the hash are the
// genuine package render, not a fixture string.
$renderer = \Card\CardRenderer::withPackageTemplates();
$ogImage = new CountingOgImage($renderer, 'unused-api-key', $cacheDir);

$cocktail = [
    'typ' => 'toml-cocktail',
    'bezeichnung' => 'Navigation',
    'kennung' => 'oxygen/hypno',
    'akte' => [
        'glass' => 'coupe',
        'garnish' => 'cherry',
        'recipe' => [
            ['ingredient' => 'gin', 'proportion' => '8 parts'],
            ['ingredient' => 'lemon juice', 'proportion' => '2 parts'],
        ],
    ],
];

// A different card → different content → must hash to a different key.
$object = [
    'type' => 'note',
    'object-id' => 'some-note',
    'description' => 'A plain note with no recipe and no code type.',
    'date' => '2025-01-01',
    'tags' => ['alpha', 'beta'],
];

/** @var array<array{0:string,1:bool}> $tests */
$tests = [];

// --- 1. cache miss: renders once, returns the URL, writes the cache file ---
$firstUrl = $ogImage->urlFor('cocktails', $cocktail);

$tests[] = [
    'cache miss returns the rendered image url',
    $firstUrl === 'https://hcti.io/v1/image/'
        . md5($renderer->renderCard('cocktails', $cocktail) . $renderer->cardCss()) . '.png',
];
$tests[] = [
    'cache miss invokes renderImageUrl exactly once',
    $ogImage->renderCount === 1,
];

$expectedKey = md5($renderer->renderCard('cocktails', $cocktail) . $renderer->cardCss());
$cacheFile = "{$cacheDir}/og-image-{$expectedKey}";
$tests[] = [
    'cache miss writes the cache file keyed by content hash',
    is_file($cacheFile) && trim((string) file_get_contents($cacheFile)) === trim($firstUrl),
];

// --- 2. cache hit: identical request short-circuits, count stays at 1 ---
$secondUrl = $ogImage->urlFor('cocktails', $cocktail);
$tests[] = [
    'cache hit returns the same url',
    $secondUrl === $firstUrl,
];
$tests[] = [
    'cache hit does NOT call renderImageUrl again (count stays at 1)',
    $ogImage->renderCount === 1,
];

// --- 3. content change → new key, render fires again ---
$objectUrl = $ogImage->urlFor('objects', $object);
$tests[] = [
    'different card content triggers a fresh render (count increments to 2)',
    $ogImage->renderCount === 2,
];
$tests[] = [
    'different card content returns a different url',
    $objectUrl !== $firstUrl,
];

$objectKey = md5($renderer->renderCard('objects', $object) . $renderer->cardCss());
$tests[] = [
    'different card content writes a distinct cache file',
    $objectKey !== $expectedKey && is_file("{$cacheDir}/og-image-{$objectKey}"),
];

$cleanup();

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
