<?php

declare(strict_types=1);

namespace Card;

/**
 * Data-driven card renderer: turns the JSON one API item carries into card
 * HTML, using the package's own card templates + CSS.
 *
 * This reproduces — without depending on them — what the app's per-type model
 * classes (Zettel / Yoga / CodeProject / Objekt / Zettel2) plus
 * ApiClient::parseCustomClass do: map the heterogeneous dodder/object field
 * shapes onto a single set of template variables, pick a card-body template,
 * then run FieldMappingTrait's two-phase render (body partial → table_card
 * wrapper). The app/api consume this package; the package cannot reach back
 * into their root-namespace classes, so the minimal mapping a *card* needs is
 * lifted here. Search/URL/page concerns beyond what the templates reference are
 * deliberately omitted (YAGNI).
 */
class CardRenderer
{
    public function __construct(private \Mustache\Engine $mustache)
    {
    }

    /**
     * Build a renderer wired to the package's bundled templates, mirroring how
     * the app constructs its engine (FilesystemLoader + ENT_QUOTES).
     */
    public static function withPackageTemplates(): self
    {
        return new self(new \Mustache\Engine([
            'loader' => new \Mustache\Loader\FilesystemLoader(
                __DIR__ . '/../templates',
                ['extension' => '.html.mustache'],
            ),
            'entity_flags' => ENT_QUOTES,
        ]));
    }

    /**
     * Render one object's card.
     *
     * @param string $type API collection name (e.g. cocktails, code, objects,
     *                      notes, yoga-objects, slides). Used alongside the
     *                      data's own typ/type field to pick the body template.
     * @param array<string,mixed> $data The item data as the API serves it.
     */
    public function renderCard(string $type, array $data): string
    {
        $card = $this->mapToCard($type, $data);
        $card['card_body'] = $this->mustache->render($card['card_body_template'], $card);

        return $this->mustache->render('table_card', $card);
    }

    /**
     * Read the package card CSS (the card-relevant rules extracted from the
     * app stylesheet). Paired with renderCard's output by the OgImage producer.
     */
    public function cardCss(): string
    {
        return file_get_contents(__DIR__ . '/../assets/card.css');
    }

    /**
     * Collapse a type's heterogeneous data shape onto the variables the card
     * templates reference, and choose the body template.
     *
     * Two data shapes coexist (see FileDataSource): the dodder cocktail shape
     * (`typ`/`bezeichnung`/`kennung`/`akte`) and the keyed-object shape
     * (`type`/`object-id`/`description`/`blob`). Both are folded into one
     * `$card` array here.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function mapToCard(string $type, array $data): array
    {
        // The inner payload lives under `akte` (cocktails) or `blob` (objects);
        // either may be a string (a plain description) or a nested array.
        $payload = $data['akte'] ?? $data['blob'] ?? [];
        $payloadFields = is_array($payload) ? $payload : [];

        $name = $data['bezeichnung']
            ?? $payloadFields['name']
            ?? $data['title']
            ?? $data['description']
            ?? '';

        $identifier = $payloadFields['kennung']
            ?? $data['kennung']
            ?? $data['object-id']
            ?? $data['id']
            ?? $data['objectId']
            ?? '';

        $description = is_string($payload)
            ? $payload
            : ($data['description'] ?? $data['bezeichnung'] ?? '');

        $recipe = $payloadFields['recipe'] ?? [];
        $glass = $payloadFields['glass'] ?? '';
        $garnish = $payloadFields['garnish'] ?? '';
        $kind = $payloadFields['kind'] ?? '';

        $objectType = $this->normalizeType($data);
        $template = $this->selectBodyTemplate($type, $objectType, $recipe);

        return [
            'card_body_template' => $template,
            'name' => $name,
            'identifier' => $identifier,
            'description' => $description,
            'recipe' => $recipe,
            'glass' => $glass,
            'garnish' => $garnish,
            'kind' => $kind,
            'icon_css_class' => $glass !== '' ? "toml-cocktail-{$glass}" : '',
            // table_card references these; cards rendered for OG images are not
            // interactive, but the wrapper still wants a stable href + id.
            'url' => $identifier,
            'search_string' => trim("{$name} {$identifier}"),
        ];
    }

    /**
     * The object's own type, normalized: prefer `typ` (cocktail shape) then
     * `type` (object shape), stripping the leading `!` dodder type sigil so
     * `!toml-project-code` matches `toml-project-code`.
     *
     * @param array<string,mixed> $data
     */
    private function normalizeType(array $data): string
    {
        $raw = $data['typ'] ?? $data['type'] ?? '';

        return ltrim((string) $raw, '!');
    }

    /**
     * Pick the body template, mirroring Zettel::__construct's switch: code-like
     * types render as a code-project card, cocktails (anything with a recipe,
     * or the cocktails collection) render the recipe card, and everything else
     * falls back to the minimal identifier+description code-project card.
     *
     * @param array<int,mixed> $recipe
     */
    private function selectBodyTemplate(string $collection, string $objectType, array $recipe): string
    {
        if ($objectType === 'toml-project-code' || $objectType === 'md' || $collection === 'code') {
            return 'card_code_project';
        }

        if ($collection === 'cocktails' || $objectType === 'toml-cocktail' || $recipe !== []) {
            return 'cocktail_card';
        }

        return 'card_code_project';
    }
}
