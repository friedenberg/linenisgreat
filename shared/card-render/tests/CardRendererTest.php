<?php

declare(strict_types=1);

// Hermetic TAP check for Card\CardRenderer: feeds representative object data
// (a cocktail, a code project, a generic object) and asserts the data-driven
// renderer reproduces the per-type card markup using the package's own
// templates. Network-free and deterministic — no hcti.io, no API.
//
// The package ships no vendor of its own, so Mustache (the only third-party
// dependency CardRenderer needs) is loaded from the app's vendor; the package
// class itself is required directly, mirroring Html2ImageTest.

require dirname(__DIR__, 3) . '/app/protected/vendor/autoload.php';
require __DIR__ . '/../src/CardRenderer.php';

$renderer = \Card\CardRenderer::withPackageTemplates();

// A cocktail: dodder shape (typ/bezeichnung/kennung/akte) as FileDataSource
// serves it for the `cocktails` collection.
$cocktail = $renderer->renderCard('cocktails', [
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
]);

// A code project: keyed-collection shape (type/object-id/description/blob).
$code = $renderer->renderCard('code', [
    'type' => '!toml-project-code',
    'object-id' => 'project-and-so-can-you',
    'description' => 'conformist — a whole-repo cross-format linter',
    'blob' => [
        'name' => 'and-so-can-you',
        'meta' => ['url' => 'https://github.com/amarbel-llc/and-so-can-you'],
    ],
]);

// A generic object: no recipe, no code type — the default note/object card.
$object = $renderer->renderCard('objects', [
    'object-id' => 'some-note',
    'description' => 'A plain note with no recipe and no code type.',
]);

/** @var array<array{0:string,1:bool}> $tests */
$tests = [];

// --- table_card wrapper is always present (two-phase render reached phase 2) ---
$tests[] = [
    'cocktail card is wrapped in the table_card container',
    str_contains($cocktail, 'class="card"') && str_contains($cocktail, 'card-contents'),
];
$tests[] = [
    'code card is wrapped in the table_card container',
    str_contains($code, 'class="card"') && str_contains($code, 'card-contents'),
];
$tests[] = [
    'object card is wrapped in the table_card container',
    str_contains($object, 'class="card"') && str_contains($object, 'card-contents'),
];

// --- title / name surfaced from the data ---
$tests[] = [
    'cocktail card shows the cocktail name',
    str_contains($cocktail, 'Navigation'),
];
$tests[] = [
    'cocktail card shows the identifier in the table_card id attribute',
    str_contains($cocktail, 'oxygen/hypno'),
];
$tests[] = [
    'code card shows the identifier (object-id)',
    str_contains($code, 'project-and-so-can-you'),
];
$tests[] = [
    'code card shows the description',
    str_contains($code, 'conformist'),
];
$tests[] = [
    'object card shows the description',
    str_contains($object, 'A plain note'),
];

// --- correct body template per type ---
// cocktail_card renders the recipe rows + glass/garnish; card_code_project does not.
$tests[] = [
    'cocktail card uses the cocktail_card body (recipe ingredients rendered)',
    str_contains($cocktail, 'gin') && str_contains($cocktail, '8 parts')
        && str_contains($cocktail, 'glass') && str_contains($cocktail, 'coupe'),
];
// card_code_project shows the identifier in a title div and has no recipe table rows.
$tests[] = [
    'code card uses the card_code_project body (no cocktail recipe markup)',
    !str_contains($code, 'tdleft small-caps'),
];
$tests[] = [
    'generic object card uses the card_code_project body (no recipe markup)',
    !str_contains($object, 'tdleft small-caps'),
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
