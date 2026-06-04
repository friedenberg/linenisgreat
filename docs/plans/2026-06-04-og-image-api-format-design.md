# Design: OG-image-per-card as a dodder-style API format

Date: 2026-06-04
Status: Approved (brainstorm) — implementation plan to follow

## Context

Individual card/detail pages should expose an Open Graph preview image (`<meta
og:image>`) so shared links render a picture of the card. The machinery for this
existed historically but only for cocktails and is currently broken: `Zettel`
still carries the full image pipeline (`getImageUrl` via `Html2Image`/hcti.io,
`getId`/md5, tmp serialization), the `og:image` template block exists
(`head.html.mustache` / `head_common.html.mustache`, `/i/{{image_id}}` gated by
`{{#meta.image}}`), but `app/public/image.php` instantiates a deleted `Cocktails`
class, there is no route to it, and `meta.image`/`image_id` is never populated.
GitHub issue #11 (undefined `Cocktail`/`Cocktails`) is a symptom of this.

The user wants the feature **restored and generalized to all card types**, made
**API-driven**, and shaped to **mirror (and eventually extend) the dodder URI
scheme** — where an object's renderings are addressed as
`dodder://objects/{id}/blob/formats/{format_id}`. The OG image becomes one such
`format_id`.

Because card rendering (templates + CSS) currently lives on the app while the API
serves only data + prebuilt HTML partials, owning the image on the API side
requires the **card templates + CSS + rendering to become a shared module** both
apps consume.

## Settled decisions

- **Purpose:** Open Graph previews only (no on-page images).
- **Timing:** request-time, lazy, cached (matches ADR 0003's read-through model).
- **Page scope:** individual detail pages only; index/section pages keep the
  site default.
- **Ownership:** the **API** owns the image as a server-side rendering of the
  object, addressed by a dodder-style formats path; `og-image` is a `format_id`.
- **Sharing mechanism:** a local **Composer path package** (chosen over a
  build-time file copy for isolation + single source of truth). PSR-4, namespace
  `Card\`, required by both `app/protected` and `api/protected` with
  `"options": {"symlink": false}` so each `vendor/` holds a self-contained copy
  that survives the `deploy-prod` rsync.

## Architecture

### 1. `shared/card-render` (Composer path package)

Owns the card-rendering domain (one source of truth):

- Card mustache templates (`table_card`, `card_body_*` / `cocktail_card`,
  `card_code_project`). Page/layout templates (`head`, `index`, `nav`) stay app-only.
- `card.css` — card-relevant CSS extracted from the app stylesheet.
- `CardRenderer` — object data → card HTML (field mapping + two-phase render,
  lifted from `FieldMappingTrait` / `Zettel`).
- `Html2Image` (moved here) + an `OgImage` format producer (card HTML + `card.css`
  → hcti.io URL).

### 2. API — dodder-style formats endpoint

Mirrors `dodder://objects/{id}/blob/formats/{format_id}`:

- `GET <type>/<id>/blob/formats/og-image` → build the card via the package over
  the object's data (`FileDataSource`), run `OgImage`, cache the hcti URL by
  content hash in `api/tmp/`, **302-redirect** to it.
- `GET <type>/<id>/blob/formats` → list available formats (dodder parity).
- Existing `<type>/<id>/html` stays as-is this pass; folding it into
  `blob/formats/html` is a follow-up.
- `format_id` = `og-image` (URL-safe slug; literal `og:image` rejected for the
  colon in a path segment).

### 3. App — emit the meta

Detail-page render (`Route` / `RouteObject` `getMeta()`) sets `meta.image = true`
and `image_id`, so the existing `{{#meta.image}}` block emits an **absolute**
`og:image`: `https://api.linenisgreat.com/<type>/<id>/blob/formats/og-image`.
Applies to all detail pages (objects, notes, yoga, cocktails, slides, code).

### 4. Generalization to all types

`CardRenderer` operates on object _data_, not per-class methods, so every type the
API serves gets `og-image` for free. The dead cocktail-specific code
(`image.php`'s `new Cocktails()`, the orphan `Tab` cocktail methods, `Zettel`'s
bespoke image methods) is removed in favour of the package — resolving #11.

### 5. Secrets / deploy

`Html2ImageApiKey` (currently app-only, gitignored) must reach the **API** host:
extend `just reveal-secrets` + `deploy-prod` to materialize/ship the hcti key on
the api side. `api/tmp/` (already gitignored) holds the URL cache.

## Data flow

```
app page  linenisgreat.com/objects/<id>
  Route::getMeta() sets meta.image + image_id
  <meta og:image="https://api.linenisgreat.com/objects/<id>/blob/formats/og-image">

crawler GET api.linenisgreat.com/objects/<id>/blob/formats/og-image
  FileDataSource.getItem(objects, <id>)  (data)
  Card\CardRenderer -> card HTML  (shared templates)
  Card\OgImage(html, card.css) -> hcti.io URL   (cached by md5 in api/tmp/)
  302 -> hcti image URL
```

## Tuning levers

- **Cache key & eviction** — content-md5 key, no eviction initially (stale
  entries orphan on content change). Revisit signal: `api/tmp` growth.
- **`card.css` scope** — start minimal; revisit signal: image looks unstyled vs
  the on-site card.
- **`format_id` spelling** (`og-image`).

## Rollback / dual-architecture

- The package is additive; the app's current page rendering keeps working while
  card rendering migrates into the package (both coexist until the app fully
  delegates).
- **Off-switch:** leave `meta.image` unset (or a config flag) → no `og:image`
  emitted, nothing calls hcti — instant revert without removing code.
- **Rollback proper:** revert the merge commit; the package is self-contained.

## Testing

- Package: unit-test `CardRenderer` (data→HTML) and `OgImage` (payload, hcti
  mockable) in isolation.
- API: extend `test-router` / add `test-formats` — assert `blob/formats/og-image`
  302s and `blob/formats` lists.
- App: assert detail pages emit the absolute `og:image` meta.

## Out of scope (follow-ups)

- Migrating `/html` into `blob/formats/html`.
- The app fully delegating page-card rendering to the package.
- #12 (phpstan ratchet).
