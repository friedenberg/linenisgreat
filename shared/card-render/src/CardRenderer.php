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
 *
 * Collection → model → body template (per the live index path,
 * RouteObjectOrObjectsIndex::renderIndex):
 *   - cocktails        → Zettel      → cocktail_card (or card_code_project for
 *                                       typ toml-project-code/md)
 *   - objects, notes   → Objekt      → card_object
 *   - code             → CodeProject → card_common
 *   - yoga, yoga-objects → Yoga      → card_object_new
 *   - slides           → Zettel2     → card_common
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
     *                      notes, yoga, yoga-objects, slides). Used alongside
     *                      the data's own typ/type field to pick the body
     *                      template and field mapping.
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
        return (string) file_get_contents(__DIR__ . '/../assets/card.css');
    }

    /**
     * Collapse a type's heterogeneous data shape onto the variables the chosen
     * card template references, and choose that template.
     *
     * The body template is collection-driven, mirroring which model class the
     * live route instantiates for each collection (see class docblock); the
     * cocktail collection additionally branches on the dodder `typ` exactly as
     * Zettel::__construct does. Each branch populates *only* the variables its
     * template reads (verified against the template sources) plus the
     * table_card wrapper's id/url/search_string.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function mapToCard(string $collection, array $data): array
    {
        $objectType = $this->normalizeType($data);
        $template = $this->selectBodyTemplate($collection, $objectType);

        return match ($template) {
            'card_object' => $this->mapObjekt($data),
            'card_common' => $collection === 'code'
                ? $this->mapCodeProject($data)
                : $this->mapZettel2($data),
            'card_object_new' => $this->mapYoga($data),
            'card_code_project' => $this->mapCodeZettel($data),
            default => $this->mapCocktail($data),
        };
    }

    /**
     * Objekt (objects/notes collections) → card_object. Keyed-object shape:
     * type / object-id / description (or duration) / date / tags. card_object
     * uses {{description}} for both the head title and the body — intentional.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function mapObjekt(array $data): array
    {
        $description = $data['description'] ?? $data['duration'] ?? '';
        $objectId = $this->extractObjectId($data);

        return [
            'card_body_template' => 'card_object',
            'type' => $data['type'] ?? '',
            'description' => $description,
            'date' => $data['date'] ?? '',
            'objectId' => $objectId,
            'tags' => $this->normalizeTags($data['tags'] ?? []),
            'identifier' => $objectId,
            'url' => $objectId,
            'search_string' => trim("{$description} {$objectId}"),
        ];
    }

    /**
     * CodeProject (code collection) → card_common. Keyed-object shape with a
     * `blob` payload: title ← blob.name ?? object-id; subtitle is always blank
     * (CodeProject never sets it); description ← blob-as-string ?? description.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function mapCodeProject(array $data): array
    {
        $blob = $data['blob'] ?? [];
        $blobFields = is_array($blob) ? $blob : [];

        $title = $blobFields['name'] ?? $data['object-id'] ?? '';
        $description = is_string($blob) ? $blob : ($data['description'] ?? '');

        return [
            'card_body_template' => 'card_common',
            'title' => $title,
            'subtitle' => '',
            'description' => $description,
            'identifier' => $title,
            'url' => $title,
            'search_string' => trim("{$title}"),
        ];
    }

    /**
     * Zettel2 (slides collection) → card_common. Array shape:
     * title / subtitle / description / tags / objectId.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function mapZettel2(array $data): array
    {
        $title = $data['title'] ?? '';
        $objectId = $this->extractObjectId($data);

        return [
            'card_body_template' => 'card_common',
            'title' => $title,
            'subtitle' => $data['subtitle'] ?? '',
            'description' => $data['description'] ?? '',
            'identifier' => $objectId,
            'url' => $objectId,
            'search_string' => trim("{$title} {$objectId}"),
        ];
    }

    /**
     * Yoga (yoga/yoga-objects collections) → card_object_new. Array shape:
     * title / type / id / description / date / duration / tags. The model
     * builds a two-row header (title + "id: …") and a fields table of
     * duration then tags — level/intensity exist in the JSON but the model
     * never renders them into the card, so they are deliberately omitted.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function mapYoga(array $data): array
    {
        $title = $data['title'] ?? '';
        $objectId = $this->extractObjectId($data);
        $tags = $this->normalizeTags($data['tags'] ?? []);

        $fields = [];
        if (isset($data['duration'])) {
            $fields[] = ['key' => 'duration', 'value' => $data['duration']];
        }
        if ($tags !== '') {
            $fields[] = ['key' => 'tags', 'value' => $tags];
        }

        return [
            'card_body_template' => 'card_object_new',
            'headers' => [
                [
                    'classes' => 'text-center title uppercase',
                    'value' => $title,
                ],
                [
                    'classes' => 'text-center small-caps text-small',
                    'value' => "id: {$objectId}",
                ],
            ],
            'fields' => $fields,
            'description' => $data['description'] ?? '',
            'date' => $data['date'] ?? '',
            'objectId' => $objectId,
            'identifier' => $objectId,
            'url' => $objectId,
            'search_string' => trim("{$title} {$objectId}"),
        ];
    }

    /**
     * Zettel cocktail branch (cocktails collection, default typ) →
     * cocktail_card. Dodder shape: bezeichnung / kennung / akte (nested or
     * string). akte.kennung overrides the top-level kennung; an akte string is
     * the description.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function mapCocktail(array $data): array
    {
        $akte = $data['akte'] ?? [];
        $akteFields = is_array($akte) ? $akte : [];

        $name = $data['bezeichnung'] ?? '';
        $identifier = $akteFields['kennung'] ?? $data['kennung'] ?? '';
        $description = is_string($akte) ? $akte : ($data['bezeichnung'] ?? '');
        $glass = $akteFields['glass'] ?? '';

        return [
            'card_body_template' => 'cocktail_card',
            'name' => $name,
            'kind' => $akteFields['kind'] ?? '',
            'recipe' => $akteFields['recipe'] ?? [],
            'glass' => $glass,
            'garnish' => $akteFields['garnish'] ?? '',
            'description' => $description,
            'icon_css_class' => $glass !== '' ? "toml-cocktail-{$glass}" : '',
            'identifier' => $identifier,
            'url' => $identifier,
            'search_string' => trim("{$name} {$identifier}"),
        ];
    }

    /**
     * Zettel code branch (cocktails collection, typ toml-project-code/md) →
     * card_code_project. The dodder code-zettel renders its identifier as the
     * title and akte-as-string (or bezeichnung) as the description.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function mapCodeZettel(array $data): array
    {
        $akte = $data['akte'] ?? [];
        $akteFields = is_array($akte) ? $akte : [];

        $identifier = $akteFields['kennung'] ?? $data['kennung'] ?? '';
        $description = is_string($akte) ? $akte : ($data['bezeichnung'] ?? '');

        return [
            'card_body_template' => 'card_code_project',
            'identifier' => $identifier,
            'description' => $description,
            'icon_css_class' => '',
            'url' => $identifier,
            'search_string' => trim("{$description} {$identifier}"),
        ];
    }

    /**
     * Object-id with the same fallback chain as FieldMappingTrait::
     * extractObjectId (object-id → id → objectId).
     *
     * @param array<string,mixed> $data
     */
    private function extractObjectId(array $data): string
    {
        return (string) ($data['object-id'] ?? $data['id'] ?? $data['objectId'] ?? '');
    }

    /**
     * Comma-join a tags array, mirroring FieldMappingTrait::normalizeTags.
     *
     * @param mixed $tags
     */
    private function normalizeTags(mixed $tags): string
    {
        if (is_array($tags)) {
            return implode(', ', $tags);
        }

        return (string) ($tags ?? '');
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
     * Pick the body template from the collection, mirroring which model class
     * the live route instantiates for each collection (RouteObjectOrObjects
     * Index plus the public/*.php entrypoints):
     *   - code                       → card_common   (CodeProject)
     *   - objects, notes             → card_object   (Objekt)
     *   - yoga, yoga-objects         → card_object_new (Yoga)
     *   - slides                     → card_common   (Zettel2)
     *   - cocktails / anything else  → Zettel's typ switch (toml-project-code
     *                                   or md → card_code_project; else the
     *                                   default cocktail_card)
     */
    private function selectBodyTemplate(string $collection, string $objectType): string
    {
        return match ($collection) {
            'code' => 'card_common',
            'objects', 'notes' => 'card_object',
            'yoga', 'yoga-objects' => 'card_object_new',
            'slides' => 'card_common',
            default => ($objectType === 'toml-project-code' || $objectType === 'md')
                ? 'card_code_project'
                : 'cocktail_card',
        };
    }
}
