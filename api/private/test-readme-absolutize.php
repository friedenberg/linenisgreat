<?php

declare(strict_types=1);

// Hermetic TAP check for ReadmeLinkAbsolutizer: the request-time PHP equivalent
// of the build-time ast-grep link rewrite (justfile build-code-github). No
// network, no autoloader — requires the one pure class directly so it runs in
// the `test` gate without composer or a server.

require __DIR__ . '/../protected/lib/ReadmeLinkAbsolutizer.php';

$abs = new ReadmeLinkAbsolutizer();
$org = 'amarbel-llc';
$repo = 'dodder';

/** @var array<array{0:string,1:bool}> $tests */
$tests = [];

$out = $abs->absolutize('<p><a href="docs/guide.md">guide</a></p>', $org, $repo);
$tests[] = [
    'relative href rewritten to blob/HEAD',
    str_contains($out, 'href="https://github.com/amarbel-llc/dodder/blob/HEAD/docs/guide.md"'),
];

$out = $abs->absolutize('<p><img src="img/logo.png"></p>', $org, $repo);
$tests[] = [
    'relative img src rewritten to raw/HEAD',
    str_contains($out, 'src="https://github.com/amarbel-llc/dodder/raw/HEAD/img/logo.png"'),
];

$out = $abs->absolutize('<a href="https://example.com/x">x</a>', $org, $repo);
$tests[] = [
    'absolute https href untouched',
    str_contains($out, 'href="https://example.com/x"'),
];

$out = $abs->absolutize('<a href="//cdn.example.com/x">x</a>', $org, $repo);
$tests[] = [
    'scheme-relative href untouched',
    str_contains($out, 'href="//cdn.example.com/x"'),
];

$out = $abs->absolutize('<a href="#install">install</a>', $org, $repo);
$tests[] = [
    'in-page anchor untouched',
    str_contains($out, 'href="#install"'),
];

$out = $abs->absolutize('<a href="mailto:a@b.com">mail</a>', $org, $repo);
$tests[] = [
    'mailto href untouched',
    str_contains($out, 'href="mailto:a@b.com"'),
];

$out = $abs->absolutize('<img src="data:image/png;base64,AAAA">', $org, $repo);
$tests[] = [
    'data: img src untouched',
    str_contains($out, 'src="data:image/png;base64,AAAA"'),
];

// An href written as literal text inside a code block is escaped text, not an
// <a> element, so it must survive untouched (the ast-grep AST-scoping parity).
$out = $abs->absolutize('<pre><code>&lt;a href="docs/x.md"&gt;</code></pre>', $org, $repo);
$tests[] = [
    'href literal inside <code> not rewritten',
    !str_contains($out, 'blob/HEAD/docs/x.md') && str_contains($out, 'docs/x.md'),
];

// UTF-8 content round-trips without mojibake.
$out = $abs->absolutize('<p>café — déjà</p>', $org, $repo);
$tests[] = [
    'UTF-8 text preserved',
    str_contains($out, 'café — déjà'),
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
