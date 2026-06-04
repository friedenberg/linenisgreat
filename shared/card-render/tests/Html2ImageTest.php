<?php

declare(strict_types=1);

// Hermetic TAP check for Card\Html2Image: subclasses the class to stub the
// network seam (post()), so getImageUrl() is asserted without hitting hcti.io.
// Requires the one pure class directly — the package has no vendor of its own,
// and a single dependency-free class needs no autoloader.

require __DIR__ . '/../src/Html2Image.php';

/**
 * Test double: records the data handed to the network seam and returns a
 * canned body instead of POSTing to hcti.io.
 */
final class FakeHtml2Image extends \Card\Html2Image
{
    /** @var array<string,string> */
    public array $captured = [];

    public function __construct(
        string $html,
        string $css,
        string $apiKey,
        private string $cannedBody,
    ) {
        parent::__construct($html, $css, $apiKey);
    }

    protected function post(array $data): string
    {
        $this->captured = $data;
        return $this->cannedBody;
    }
}

/** @var array<array{0:string,1:bool}> $tests */
$tests = [];

$ok = new FakeHtml2Image('<p>hi</p>', 'p{color:red}', 'key', '{"url":"https://hcti.io/v1/image/abc.png"}');
$tests[] = [
    'getImageUrl returns url from well-formed response',
    $ok->getImageUrl() === 'https://hcti.io/v1/image/abc.png',
];

$tests[] = [
    'post() receives both html and css',
    ($ok->captured['html'] ?? null) === '<p>hi</p>'
        && ($ok->captured['css'] ?? null) === 'p{color:red}',
];

$threw = false;
$missing = new FakeHtml2Image('<p>hi</p>', '', 'key', '{"noturl":"x"}');
try {
    $missing->getImageUrl();
} catch (\RuntimeException $e) {
    $threw = true;
}
$tests[] = [
    'getImageUrl throws RuntimeException when response lacks url',
    $threw,
];

$threwBadJson = false;
$badJson = new FakeHtml2Image('<p>hi</p>', '', 'key', 'not json');
try {
    $badJson->getImageUrl();
} catch (\RuntimeException $e) {
    $threwBadJson = true;
}
$tests[] = [
    'getImageUrl throws RuntimeException on non-JSON body',
    $threwBadJson,
];

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
