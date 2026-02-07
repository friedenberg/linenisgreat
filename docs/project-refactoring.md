# Data and Template Hydration Patterns

This document captures the current patterns around object data and template hydration in preparation for refactoring.

## 1. Data Model Classes

The codebase has several model classes that follow a consistent pattern:

| Class | File | Purpose |
|-------|------|---------|
| `Objekt` | `protected/lib/Objekt.php` | Generic object model |
| `Zettel2` | `protected/lib/Zettel2.php` | Article/common content |
| `Yoga` | `protected/lib/Yoga.php` | Yoga classes with structured fields |
| `CodeProject` | `protected/lib/CodeProject.php` | Code projects with nested blob data |
| `Zettel` | `protected/lib/Zettel.php` | Legacy serialized model (cocktails) |

### Common Constructor Pattern

Each model accepts a JSON associative array `$j` and optional `$urlPrefix`, then maps fields with fallbacks:

```php
public function __construct($j, $urlPrefix = "")
{
    $this->type = $j['type'];
    $this->objectId = $j['object-id'] ?? $j['id'] ?? "";
    $this->description = $j['description'] ?? $j['duration'] ?? "";

    // Tags normalization (array vs string)
    if (is_array($j['tags'])) {
        $this->tags = implode(', ', $j['tags'] ?? []);
    } else {
        $this->tags = $j['tags'] ?? "";
    }
}
```

### URL Generation

All models build URLs from objectId and title with encoding:

```php
$titleUrlEncoded = urlencode($this->title);
$this->url = "$urlPrefix$this->objectId/$titleUrlEncoded";
```

## 2. Two-Phase Template Hydration

All models implement `getHtml($mustache)` with a two-phase rendering pattern:

```php
public function getHtml($mustache): string
{
    if (!isset($this->html)) {
        // Phase 1: Render card body with object data
        $this->card_body = $mustache->render($this->card_body_template, $this);

        // Phase 2: Wrap in container template
        $this->html = $mustache->render('table_card', $this);
    }
    return $this->html;
}
```

Each model specifies its own `card_body_template`:

| Model | Template |
|-------|----------|
| `Objekt` | `card_object` |
| `Zettel2` | `card_common` |
| `Yoga` | `card_object_new` |
| `CodeProject` | `card_common` |
| `Zettel` | `cocktail_card` or `card_code_project` |

All cards then wrap in the shared `table_card` wrapper template.

### Template Files

- **Card templates**: `protected/lib/templates/card_*.html.mustache`
- **Layout templates**: `protected/lib/templates/common.html.mustache`, `object.html.mustache`
- **Partials**: `head.html.mustache`, `nav.html.mustache`, `search_box.html.mustache`

## 3. JSON Parsing via ZettelParser

`ZettelParser.php` provides a generic mechanism to load JSON and instantiate model classes dynamically:

```php
public function parse($urlPrefix = null): array
{
    return array_map(
        function ($c) use ($urlPrefix) {
            if (!is_null($urlPrefix)) {
                $object = new $this->className($c, $urlPrefix);
            } else {
                $object = new $this->className($c);
            }
            return $object;
        },
        $this->getRaw(),
    );
}

public function parseCustomClass($className, $urlPrefix = null): array
{
    // Same pattern but with externally specified class name
}
```

## 4. Search Index Generation

All models build `search_array` for client-side filtering using a normalization pipeline:

```php
// Build search string from multiple fields
$this->search_string = "$this->name $this->objectId $this->description $this->type $this->tags";

// Normalize: ASCII transliteration, lowercase, tokenize
$this->search_string = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $this->search_string);
$this->search_array = preg_split("/[\W]+/", $this->search_string);
$this->search_array = array_combine($this->search_array, $this->search_array);
```

The `Yoga` model also supports prefixed search terms for structured queries:

```php
$this->search_string .= "d-$this->duration l-{$j['level']} i-{$j['intensity']}";
```

### Matching Logic

```php
// Zettel.php
public function matches($query)
{
    return count(array_intersect($query, $this->search_array)) > 0;
}
```

## 5. Template Context Aggregation

Route classes use `makeTemplateArgs()` to merge site-wide context with page-specific data:

```php
private function makeTemplateArgs(...$extra): array
{
    return array_merge(
        [
            'nav' => array_values($this->nav->tiles),
            'meta' => $this->getMeta(),
            'stylesheets' => [
                "stylesheet",
                "zettels",
                "fonts",
            ],
        ],
        ...$extra,
    );
}
```

### Rendering Flow

1. Parse JSON data into object array via `getObjects()`
2. Call `getHtml($mustache)` on each object to generate HTML strings
3. Pass array of HTML strings as `objects` to template
4. Template iterates with `{{#objects}} {{{.}}} {{/objects}}`

## 6. Data Structures

### Two JSON Formats

**Keyed dictionaries** (`objects.json`, `code.json`):
```json
{
  "radon/magnemite": {
    "object-id": "radon/magnemite",
    "type": "!md",
    "description": "zit chrome integration",
    "tags": ["project-2021-zit-features"]
  }
}
```

**Arrays** (`yoga.json`):
```json
[
  {
    "id": "22021",
    "title": "Quiet Mental Chatter Hatha",
    "type": "class",
    "duration": 45
  }
]
```

### Nested Metadata (`code.json`)

```json
{
  "dodder": {
    "object-id": "project-dodder",
    "type": "!toml-project-code",
    "blob": {
      "meta": {
        "name": "code.linenisgreat.com/dodder",
        "template": "code_go_import",
        "url": "https://www.github.com/friedenberg/dodder"
      },
      "name": "dodder"
    }
  }
}
```

---

## Refactoring Opportunities

### 1. Inconsistent Field Mapping - COMPLETED

**Status:** Refactored using `FieldMappingTrait`

Created `protected/lib/FieldMappingTrait.php` with shared helper methods:

- `extractObjectId($j)` - Extracts object ID with fallback keys (`object-id`, `id`, `objectId`)
- `normalizeTags($tags)` - Converts array to comma-separated string, passes through strings
- `buildUrl($urlPrefix, $objectId, $title)` - Builds URL-safe paths with encoding
- `buildSearchArray($searchString)` - Normalizes and tokenizes for search (ASCII transliteration, HTML stripping, lowercase, tokenization)
- `extractWithFallback($j, $keys, $default)` - Generic fallback extraction

Updated models to use the trait:
- `Objekt.php`
- `Zettel2.php`
- `Yoga.php`
- `CodeProject.php`

### 2. Duplicate `getHtml()` Implementations

Nearly identical two-phase rendering exists in each model. Could be:
- A trait with the common implementation
- An abstract base class with a template method pattern

### 3. Hardcoded Template Assignment

Each model hardcodes its `card_body_template`. Could be:
- Configurable via constructor
- Convention-based (derive from class name)

### 4. Search Indexing Duplication

The same transliteration/tokenization pattern repeats across models. Could be extracted to a utility class or trait.

### 5. Mixed JSON Structures

Some data uses keyed dictionaries, others use arrays. This causes complexity in parsing. Consider standardizing on one format.

### 6. Legacy Serialization

`Zettel.php` uses PHP serialization (`serialize`/`unserialize`) which is fragile across code changes. Consider migrating to JSON.
