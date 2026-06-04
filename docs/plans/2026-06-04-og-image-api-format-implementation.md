# OG-image-per-card (dodder-style API format) Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use eng:subagent-driven-development to implement this plan task-by-task.

**Goal:** Serve an Open Graph preview image for every card/detail page as a dodder-style API format resource (`<type>/<id>/blob/formats/og-image`), with card rendering factored into a shared Composer package used by both the app and the API.

**Architecture:** A local Composer path package `shared/card-render` (PSR-4 `Card\`, installed with `symlink:false` into each app's `vendor/`) owns card templates + `card.css` + a data-driven `CardRenderer` + `Html2Image` + an `OgImage` format producer. The API builds a card from object data via the package, rasterizes it through hcti.io (request-time, cached in `api/tmp/`), and 302-redirects. App detail pages emit an absolute `og:image` meta pointing at the API. The dead cocktail-only image code is removed (resolves #11).

**Tech Stack:** PHP 8.4, Composer (path repository), Mustache v3, hcti.io (Html2Image), treelint gate (php-cs-fixer + phpstan level 1 + baseline), TAP bash + standalone-PHP tests (no phpunit — mirror `api/private/test-readme-absolutize.php`).

**Rollback:** Purely additive until the final dead-code removal. Off-switch: leave `meta.image` unset → no `og:image` emitted, hcti never called. Full revert: revert the merge commit (the package is self-contained).

**Design reference:** `docs/plans/2026-06-04-og-image-api-format-design.md`

---

## Conventions for every task

- Run builds/tests through `nix develop -c just <recipe>` (or `nix develop -c <cmd>`); never raw `composer`/`php` outside the devShell.
- After code changes, verify the gate compiles clean: `nix develop -c treelint check` (php-cs-fixer + phpstan). New PHP debt at phpstan level 1 must be **fixed**, not baselined, unless genuinely deferred.
- Commit after each task with a signed commit (piggy-agent). One logical change per commit.
- The package has its own namespace `Card\`; the apps remain root-namespace. Reference package classes as `Card\CardRenderer`, etc.

---

## Phase 0 — Scaffold the shared package and wire it into both apps

### Task 0.1: Create the `shared/card-render` package skeleton

**Files:**

- Create: `shared/card-render/composer.json`
- Create: `shared/card-render/src/.gitkeep`

**Step 1:** Write `shared/card-render/composer.json`:

```json
{
  "name": "linenisgreat/card-render",
  "type": "library",
  "require": { "mustache/mustache": "^3.0" },
  "autoload": { "psr-4": { "Card\\": "src/" } }
}
```

**Step 2:** `mkdir -p shared/card-render/src && touch shared/card-render/src/.gitkeep`

**Step 3:** Commit: `feat(card-render): scaffold shared card-render package`

### Task 0.2: Require the package from app and api via path repo

**Files:**

- Modify: `app/protected/composer.json`
- Modify: `api/protected/composer.json`

**Step 1:** Add to BOTH composer.json a path repository + require. For `app/protected/composer.json` the relative path is `../../shared/card-render`; for `api/protected/composer.json` it is also `../../shared/card-render`. Add:

```json
"repositories": [
  { "type": "path", "url": "../../shared/card-render", "options": { "symlink": false } }
],
"require": { "...": "...", "linenisgreat/card-render": "*" }
```

(Keep `mustache/mustache` in app; the api now gets mustache transitively via the package — that is intended.)

**Step 2:** Run `nix develop -c just build-php-composer`. Expected: both lockfiles update, `linenisgreat/card-render` copied (not symlinked) into each `vendor/`.

**Step 3:** Verify autoload: `nix develop -c php -r 'require "app/protected/vendor/autoload.php"; var_dump(class_exists("Card\\Placeholder"));'` (after adding a throwaway `Card\Placeholder` class, or skip until Task 1).

**Step 4:** Commit: `feat(card-render): require package from app and api`

> NOTE: confirm `deploy-prod` rsync ships the copied package (it rsyncs `*/protected` incl. `vendor/`; with `symlink:false` the package is real files). If `composer.lock` for the path package pins a dev hash, document that `build-php-composer` must run before deploy (it already does).

---

## Phase 1 — Move Html2Image into the package

### Task 1.1: Move `Html2Image` to `Card\Html2Image`

**Files:**

- Create: `shared/card-render/src/Html2Image.php` (namespace `Card;`, class `Html2Image`)
- Create: `shared/card-render/tests/Html2ImageTest.php`
- Delete (later, Task 8): `app/protected/lib/Html2Image.php`

**Design note:** Decouple the API key from the class so it is testable and so each app supplies its own key. Constructor takes the key:

```php
namespace Card;

class Html2Image
{
    public function __construct(
        private string $html,
        private string $css,
        private string $apiKey,
    ) {}

    public function getImageUrl(): string { /* curl hcti.io, return $res['url'] */ }
}
```

**Step 1 (test):** Write `tests/Html2ImageTest.php` as a standalone assertion script (mirror `api/private/test-readme-absolutize.php`): assert the POST body is built from html+css and that a non-2xx/missing `url` throws (inject a fake transport via a protected `post()` seam you can override in a subclass). Keep it network-free.

**Step 2:** Run `nix develop -c php shared/card-render/tests/Html2ImageTest.php` → FAIL (class missing).

**Step 3:** Implement `Card\Html2Image` with a protected `post(array $data): string` seam wrapping curl, so the test overrides it.

**Step 4:** Run the test → PASS.

**Step 5:** Add a just recipe `test-card-render` (group post-build) that runs every `shared/card-render/tests/*.php` via `php`. Commit: `feat(card-render): add Card\Html2Image with injectable key + test`

> Do NOT delete `app/protected/lib/Html2Image.php` yet (Zettel still uses it) — that happens in Phase 8 after the app migrates.

---

## Phase 2 — Extract `card.css`

### Task 2.1: Extract card-relevant CSS into the package

**Files:**

- Create: `shared/card-render/assets/card.css`
- Modify: `app/public/assets/stylesheet.css` (import or keep duplicate temporarily — see note)

**Step 1:** Identify the rules that style a card (`.table_card`, `cocktail_card`, `card_code_project`, icon classes). Copy them verbatim into `shared/card-render/assets/card.css`.

**Step 2 (tuning lever — `card.css` scope):** Start minimal; the OG image only needs the card to look like the on-site card. Signal to expand: rendered image looks unstyled.

**Step 3:** Leave the app stylesheet untouched for now (the app page still uses its full stylesheet). The app fully consuming `card.css` is a follow-up; this pass only needs `card.css` to exist for the API image render.

**Step 4:** Commit: `feat(card-render): extract card.css`

---

## Phase 3 — `CardRenderer` (data → card HTML), data-driven for all types

### Task 3.1: Move card templates into the package

**Files:**

- Create: `shared/card-render/templates/table_card.html.mustache` (+ `card_body_*` / `cocktail_card` / `card_code_project`) — copied from `app/protected/lib/templates/`
- Keep app's page/layout templates (`head*`, `index`, `nav`) where they are.

**Step 1:** Copy the card-level templates into `shared/card-render/templates/`. Commit: `feat(card-render): move card templates into package`

### Task 3.2: Implement `Card\CardRenderer`

**Files:**

- Create: `shared/card-render/src/CardRenderer.php`
- Create: `shared/card-render/tests/CardRendererTest.php`

**Design note:** `CardRenderer` reproduces, data-driven, what `ApiClient::parseCustomClass` + the model classes (`Zettel`/`Yoga`/`CodeProject`/`Objekt`/`Zettel2`) do to pick a `card_body_template` + map fields, then runs the two-phase render from `FieldMappingTrait::getHtml`. Type→template selection mirrors `Zettel::__construct`'s switch (`toml-project-code`/`md` → `card_code_project`; default → `cocktail_card`). Build a Mustache engine pointed at `shared/card-render/templates`.

```php
namespace Card;
class CardRenderer
{
    public function __construct(private \Mustache\Engine $mustache) {}
    public static function withPackageTemplates(): self { /* FilesystemLoader on ../templates */ }
    public function renderCard(string $type, array $data): string { /* map -> card_body -> table_card */ }
    public function cardCss(): string { return file_get_contents(__DIR__ . '/../assets/card.css'); }
}
```

**Step 1 (test):** `CardRendererTest.php`: feed representative data for each type (cocktail, code, generic object) and assert the output contains the expected card markers (e.g. `table_card` wrapper, the title, the type-specific body class). Mirror the field shapes `Zettel` expects (`bezeichnung`, `kennung`, `akte`, ...).

**Step 2:** Run `nix develop -c just test-card-render` → FAIL.

**Step 3:** Implement `CardRenderer` (lift mapping from `Zettel`/`FieldMappingTrait`).

**Step 4:** Run → PASS.

**Step 5:** Commit: `feat(card-render): add data-driven CardRenderer + tests`

---

## Phase 4 — `OgImage` format producer

### Task 4.1: Implement `Card\OgImage`

**Files:**

- Create: `shared/card-render/src/OgImage.php`
- Create: `shared/card-render/tests/OgImageTest.php`

**Design note:** Ties `CardRenderer` + `Html2Image` and owns the content-hash cache.

```php
namespace Card;
class OgImage
{
    public function __construct(
        private CardRenderer $renderer,
        private string $apiKey,
        private string $cacheDir,   // e.g. api/tmp
    ) {}
    public function urlFor(string $type, array $data): string {
        $html = $this->renderer->renderCard($type, $data);
        $css  = $this->renderer->cardCss();
        $key  = md5($html . $css);                       // tuning lever: cache key
        $cache = "{$this->cacheDir}/og-image-{$key}";
        if (is_file($cache)) return file_get_contents($cache);
        $url = (new Html2Image($html, $css, $this->apiKey))->getImageUrl();
        file_put_contents($cache, $url);
        return $url;
    }
}
```

**Step 1 (test):** Assert cache hit short-circuits (pre-seed `cacheDir`, inject a renderer + a fake key; assert no hcti call when cached). Use a temp dir.

**Step 2–4:** Red → implement → green via `just test-card-render`.

**Step 5:** Commit: `feat(card-render): add OgImage format producer with content-hash cache`

> **Tuning lever (cache eviction):** none initially; stale entries orphan on content change (md5 key). Revisit signal: `api/tmp` growth.

---

## Phase 5 — API: dodder-style formats endpoint

### Task 5.1: Add `sendRedirect` to `ApiResponse`

**Files:** Modify: `api/protected/lib/ApiResponse.php`

**Step 1 (test):** Add to a new `api/private/test-api-response.php` (standalone) an assertion that `sendRedirect($url)` sets a 302 + `Location` (capture via `headers_list()` in a CLI-safe shim, or assert the method exists + returns the URL).

**Step 2–4:** Implement:

```php
public function sendRedirect(string $url, int $statusCode = 302): void
{
    $this->setCorsHeaders();
    http_response_code($statusCode);
    header("Location: {$url}");
}
```

**Step 5:** Commit: `feat(api): ApiResponse::sendRedirect`

### Task 5.2: Add the formats routes to `ApiRouter`

**Files:**

- Modify: `api/protected/lib/ApiRouter.php`
- Modify: `api/public/index.php` (or wherever the router is constructed) to pass the hcti key + cache dir, OR construct `OgImage` inside the handler.
- Test: extend `app/private/test-router.sh` is app-side; add an API check to the existing `test-router`/`test-code` harness or a new `just test-formats`.

**Design note:** Register, ordered BEFORE the generic `<type>/<id>` item route so `blob/formats/...` is not swallowed as an id:

```php
// formats list
$this->get("([\\w-]+)/([^/]+)/blob/formats", function ($type, $id) use ($ds, $res) {
    // 404 if item missing; else JSON listing [{format_id, uri}] incl og-image
});
// og-image format -> 302
$this->get("([\\w-]+)/([^/]+)/blob/formats/og-image", function ($type, $id) use ($ds, $res) {
    $item = $ds->getItem($type, $id);
    if ($item === null) { $res->sendNotFound("..."); return; }
    $url = (new Card\OgImage(Card\CardRenderer::withPackageTemplates(), GithubToken::... /* hcti key */, __DIR__.'/../../tmp'))->urlFor($type, $item);
    $res->sendRedirect($url);
});
```

- `format_id` = `og-image` (tuning lever: spelling).
- The hcti key class on the API side is added in Phase 7; until then guard with a clear 503 if the key class is absent (mirrors `test-readme-live`'s token guard).

**Step 1 (test):** In `just test-formats` (new recipe; spins the api server like `test-router`): assert `GET /objects/<known-id>/blob/formats` returns JSON listing `og-image`; assert `GET /objects/<known-id>/blob/formats/og-image` returns 302 (or 503 when no key) and NOT 404; assert `blob/formats` does not shadow the plain `<type>/<id>` item route.

**Step 2–4:** Red → implement routes (mind ordering vs the item route) → green.

**Step 5:** Commit: `feat(api): dodder-style blob/formats endpoint with og-image (closes #11 groundwork)`

---

## Phase 6 — App: emit the `og:image` meta on detail pages

### Task 6.1: Populate `meta.image` + `image_id` in detail render

**Files:**

- Modify: `app/protected/lib/Route.php` and/or `app/protected/lib/RouteObject.php` (the `getMeta()` path used by `object_with_metadata.php`, `yoga_object.php`, `object.php`, `code.php`).
- Modify: templates already reference `{{#meta.image}}` + `{{meta.url}}/i/{{image_id}}` — change to the API formats URL (see note).
- Test: `app/private/test-router.sh` (assert detail page HTML contains the og:image meta).

**Design note (URL shape):** The existing template emits `{{meta.url}}/i/{{image_id}}`. Update the og:image block (in `head.html.mustache` + `head_common.html.mustache`) to emit the absolute API formats URL:
`https://api.linenisgreat.com/{{image_type}}/{{image_id}}/blob/formats/og-image` (drive host from the `API_BASE_URL` env, exposed into meta). `getMeta()` sets `meta.image = true`, `image_type` (the API collection name), and `image_id` (the object id) only on single-card detail pages.

**Step 1 (test):** Extend `test-router.sh`: a detail-page request returns HTML containing `og:image` and the `/blob/formats/og-image` path. Index pages do NOT contain it.

**Step 2–4:** Red → set the meta fields in `getMeta()` + update the two head templates → green.

**Step 5 (off-switch):** Confirm leaving `meta.image` unset on index pages suppresses the tag (rollback lever). Commit: `feat(app): emit absolute og:image meta on detail pages`

---

## Phase 7 — Secrets / deploy: hcti key on the API host

### Task 7.1: Materialize + ship the hcti key to the API

**Files:**

- Modify: `justfile` (`reveal-secrets` writes the hcti key class to the **api** side too, e.g. `api/protected/lib/Html2ImageApiKey.php`).
- Modify: `.gitignore` (ignore the api-side key class).
- Modify: `justfile` (`deploy-prod` already rsyncs `api/protected`; ensure the key + `card.css`/templates copied into `vendor/` ship).

**Step 1:** Update `reveal-secrets`'s php wrapper to also emit the api-side `Html2ImageApiKey` (same constant, api path). Add the api path to `.gitignore`.

**Step 2:** Run `nix develop -c just reveal-secrets` (requires piggy unlock) → verify both app + api key files exist, gitignored.

**Step 3:** Manual check: `nix develop -c just test-formats` now returns 302 (not 503) for a known id.

**Step 4:** Commit: `feat(deploy): materialize hcti key on the api side`

> If piggy can't be unlocked in-session, STOP and ask the user to unlock piggy-agent (do not bypass).

---

## Phase 8 — Remove dead cocktail code (resolves #11) + app delegates to package

### Task 8.1: App card rendering delegates to `Card\CardRenderer`; drop bespoke image methods

**Files:**

- Modify: `app/protected/lib/Zettel.php` (remove `getImageUrl`/`getId`/`getLocalPath`/`writeToPath`/`image_id`/`fromPath`; keep data mapping or delegate render to the package), `FieldMappingTrait.php` if image bits moved.
- Modify: `app/public/cocktails.php` if it referenced removed methods (it uses `getZettels($mustache)` — keep).
- Delete: `app/public/image.php` (the `new Cocktails()` orphan) — its replacement is the API formats endpoint.
- Modify: `app/protected/lib/Tab.php` (remove `getCocktailForQuery`, `getCocktailWithId`, `getRandomCocktail`, `getTodayCocktail`).
- Delete: `app/protected/lib/Html2Image.php` (now `Card\Html2Image`); update `Zettel.php:139` user to `Card\Html2Image` or remove if image gen fully moved to API.

**Step 1 (test):** `nix develop -c just test-router test-code` still green (cocktails/objects/code pages render).

**Step 2:** Remove the dead methods + `image.php`. Update any `Html2Image` references to `Card\Html2Image`.

**Step 3:** Run `nix develop -c treelint check`. The phpstan findings for `Cocktail`/`Cocktails`/`$other_path`/`getZettels` arg-count should now be GONE.

**Step 4:** Regenerate the phpstan baseline (entries should shrink):
`nix develop -c sh -c 'phpstan analyse --memory-limit=1G --generate-baseline phpstan-baseline.neon $(git ls-files "*.php" | grep -vE "vendor/")'`

**Step 5:** Commit: `refactor: remove dead cocktail image code, delegate to card-render (closes #11)`

---

## Phase 9 — Final verification

### Task 9.1: Full gate + manual smoke

**Step 1:** `nix develop -c just` (full default: treelint check + test + test-code) → green.
**Step 2:** `nix develop -c just test-card-render test-formats` → green.
**Step 3:** Manual: `nix develop -c just deploy-local-fast`, load a detail page, confirm the `og:image` meta points at `.../blob/formats/og-image`, and that hitting that API URL 302s to an hcti image (requires revealed key).
**Step 4:** Update `CLAUDE.md` (architecture section: shared package + formats endpoint) and the design doc status.
**Step 5:** Commit: `docs: note card-render package + formats endpoint`

---

## Testing summary

- **Package unit tests** (`shared/card-render/tests/*.php`, run by `just test-card-render`): `Html2Image` (injectable transport), `CardRenderer` (per-type data→HTML), `OgImage` (cache hit/miss).
- **API** (`just test-formats`): `blob/formats` listing, `blob/formats/og-image` 302/503, route-ordering vs item route.
- **App** (`test-router.sh`): detail pages emit absolute `og:image`; index pages do not.
- **Gate**: `treelint check` (php-cs-fixer + phpstan level 1) stays green; baseline shrinks after Phase 8.

## Tuning levers (revisit against real usage)

1. **Cache key/eviction** — content-md5, no eviction. Signal: `api/tmp` growth.
2. **`card.css` scope** — minimal. Signal: image looks unstyled.
3. **`format_id` spelling** — `og-image`.

## Out of scope (follow-ups)

- Fold `<type>/<id>/html` into `<type>/<id>/blob/formats/html`.
- App page rendering fully delegating to `Card\CardRenderer` (beyond Phase 8's image-method removal).
- #12 (phpstan ratchet).
