<?php

declare(strict_types=1);

// Hermetic TAP check for Card\CardRenderer: feeds representative object data
// for every live card type (a cocktail, a code project, a generic object, a
// yoga class, a slide) and asserts the data-driven renderer reproduces the
// per-type card markup using the package's own templates, picking the SAME
// body template the live model class would (cocktails→cocktail_card,
// objects/notes→card_object, code→card_common, yoga→card_object_new,
// slides→card_common). Network-free and deterministic — no hcti.io, no API.
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

// A generic object (objects collection): keyed shape with type/object-id/
// description/date/tags, rendered via the Objekt model → card_object.
$object = $renderer->renderCard('objects', [
    'type' => 'note',
    'object-id' => 'some-note',
    'description' => 'A plain note with no recipe and no code type.',
    'date' => '2025-01-01',
    'tags' => ['alpha', 'beta'],
]);

// A note (notes collection): same Objekt model / card_object as objects.
$note = $renderer->renderCard('notes', [
    'type' => 'note',
    'object-id' => 'a-note',
    'description' => 'A note from the notes collection.',
    'date' => '2025-02-02',
]);

// A yoga class: array shape (title/type/id/description/date/duration/tags),
// rendered via the Yoga model → card_object_new. level/intensity present in
// the JSON must NOT appear in the card.
$yoga = $renderer->renderCard('yoga', [
    'title' => 'Quiet Mental Chatter Hatha',
    'type' => 'class',
    'id' => '22021',
    'description' => 'A practice to quiet your mind.',
    'date' => '2025-01-28T20:15:27.763Z',
    'duration' => 45,
    'intensity' => '2',
    'level' => '2',
    'tags' => ['calm', 'breath'],
]);

// A slide: array shape (title/subtitle/description/tags/objectId), rendered
// via the Zettel2 model → card_common.
$slide = $renderer->renderCard('slides', [
    'title' => 'How Etsy Ships Apps',
    'subtitle' => '2025-02-23',
    'description' => 'In which Etsy transforms its app release process.',
    'tags' => '',
    'objectId' => 'anterior/nauru',
]);

/** @var array<array{0:string,1:bool}> $tests */
$tests = [];

// --- table_card wrapper is always present (two-phase render reached phase 2) ---
foreach (
    [
        'cocktail' => $cocktail,
        'code' => $code,
        'object' => $object,
        'note' => $note,
        'yoga' => $yoga,
        'slide' => $slide,
    ] as $label => $html
) {
    $tests[] = [
        "{$label} card is wrapped in the table_card container",
        str_contains($html, 'class="card"') && str_contains($html, 'card-contents'),
    ];
}

// --- cocktails → cocktail_card (recipe rows + glass/garnish + live icon div) ---
$tests[] = [
    'cocktail card shows the cocktail name',
    str_contains($cocktail, 'Navigation'),
];
$tests[] = [
    'cocktail card shows the identifier in the table_card id attribute',
    str_contains($cocktail, 'oxygen/hypno'),
];
$tests[] = [
    'cocktail card uses the cocktail_card body (recipe ingredients + glass + live icon)',
    str_contains($cocktail, 'gin') && str_contains($cocktail, '8 parts')
        && str_contains($cocktail, 'coupe')
        && str_contains($cocktail, '<div class="icon icon-')
        && !str_contains($cocktail, '<!-- <div class="icon'),
];

// --- code → card_common (title from blob.name, description), NOT card_code_project ---
$tests[] = [
    'code card shows the title from blob.name',
    str_contains($code, 'and-so-can-you'),
];
$tests[] = [
    'code card shows the description',
    str_contains($code, 'conformist'),
];
// card_common's head has a `text-center i` subtitle div; card_code_project does
// not. This is the key fidelity fix (code renders card_common, not the code
// project card), so assert the card_common marker is present and the
// card_code_project marker (commented icon div) is absent.
$tests[] = [
    'code card uses card_common (subtitle div present), NOT card_code_project',
    str_contains($code, 'class="text-center i"')
        && !str_contains($code, '<!-- <div class="icon'),
];

// --- objects/notes → card_object (description in head AND body, type/objectId/date) ---
// card_object puts {{description}} in both the head title and the body, and
// renders {{type}} and {{objectId}} in the head plus {{date}} in the body.
$tests[] = [
    'object card uses card_object (description in head+body, type/objectId/date)',
    substr_count($object, 'A plain note with no recipe and no code type.') >= 2
        && str_contains($object, '<div class="text-center">note</div>')
        && str_contains($object, 'some-note')
        && str_contains($object, '2025-01-01'),
];
$tests[] = [
    'notes collection also renders card_object (description in head+body)',
    substr_count($note, 'A note from the notes collection.') >= 2
        && str_contains($note, 'a-note'),
];

// --- yoga → card_object_new (headers with title + "id:", duration/tags fields) ---
// card_object_new is the only body with the "id: " header row and a fields
// table carrying duration/tags. level/intensity must NOT leak into the card.
$tests[] = [
    'yoga card uses card_object_new (title header + "id:" header)',
    str_contains($yoga, 'Quiet Mental Chatter Hatha')
        && str_contains($yoga, 'id: 22021'),
];
$tests[] = [
    'yoga card renders duration and tags fields',
    str_contains($yoga, 'duration') && str_contains($yoga, '45')
        && str_contains($yoga, 'tags') && str_contains($yoga, 'calm, breath'),
];
$tests[] = [
    'yoga card does NOT render level/intensity (model never surfaces them)',
    !str_contains($yoga, '>level<') && !str_contains($yoga, '>intensity<'),
];

// --- slides → card_common (title/subtitle/description) ---
$tests[] = [
    'slide card uses card_common (title + subtitle + description)',
    str_contains($slide, 'How Etsy Ships Apps')
        && str_contains($slide, '2025-02-23')
        && str_contains($slide, 'Etsy transforms its app release process')
        && str_contains($slide, 'class="text-center i"'),
];
$tests[] = [
    'slide card shows the objectId in the table_card id attribute',
    str_contains($slide, 'anterior/nauru'),
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
