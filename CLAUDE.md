# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

Personal portfolio site (linenisgreat.com) split into three independently
deployed PHP applications: a Mustache-templated frontend (`app/`), a JSON REST
API (`api/`), and a stateless feed app (`atom/`). Each deploys to its own
NearlyFreeSpeech.NET SSH host via rsync.

## Commands

All commands require the Nix devShell: `nix develop -c just <recipe>`

```sh
just test                # Run all tests (htaccess + router integration)
just test-htaccess       # Validate generated .htaccess rules (11 TAP tests)
just test-router         # Start app + API servers, test HTTP routes (24 TAP tests)
just build-php-composer  # composer install in app/, api/, and atom/
just build               # Build object data from dodder (requires `der` binary)
just deploy-local        # Run all three servers locally (app:2222, api:2223, atom:2224)
just deploy-local-prod-api  # Run app locally against production API
just deploy-prod         # rsync to production + web-kick (requires SSH access)
just build-htaccess      # Regenerate app/ and atom/ .htaccess from their routers
just test-feeds          # Start API + atom, assert Atom/RSS feeds (8 TAP tests)
```

## Architecture

### Three-Application Split

All applications share the same `{public,protected,private,conf}` directory
convention expected by NFSN. The `app/`, `api/`, or `atom/` prefix is stripped by
rsync during deploy — production sees a flat layout.

- **`app/`** → `linenisgreat.com` — PHP frontend with Mustache templates
- **`api/`** → `api.linenisgreat.com` — stateless JSON API backed by flat files
- **`atom/`** → `atom.linenisgreat.com` — stateless feed app: renders any
  collection as Atom/RSS (see Feeds below)

The frontend connects to the API via `API_BASE_URL` env var (default:
`https://api.linenisgreat.com`) and links to feeds via `ATOM_BASE_URL` (default:
`https://atom.linenisgreat.com`). The API accepts `CORS_ORIGIN` env var (default:
`https://linenisgreat.com`). The atom app reads `API_BASE_URL` (data source) and
`SITE_BASE_URL` (default `https://linenisgreat.com`, for item links).

### Events, Framework Object Footer, Feeds (ADR-0004)

The **events** tab filters the `!event` dodder type (a TOML/human form of a
CalDAV `VEVENT`). Events render as cards (`card_event`) and as full object views
(`app/public/events.php`). Three pieces are **framework-level**, with events as
their first consumer:

- **Object footer** — `ObjectFooter::build($updated, $links)` +
  `object_footer.html.mustache` render a "last updated" line above a
  pipe-separated row of `{label, href, download}` links, surfaced via
  `meta.footer`. Code's footer (github | license) and events' footer
  (ics | add to cal) both use it.
- **ICS format** — `GET events/<id>/blob/formats/ics` (`IcsBuilder`,
  `text/calendar`) is a dodder-style format next to `og-image`. `ics` downloads
  it (https + `Content-Disposition: attachment`); "add to cal" points at the
  same resource over `webcal://` so the OS opens the default calendar app.
- **Feeds** — the `atom/` app serves `GET <type>/feed.atom` and `<type>/feed.rss`
  for any collection (`FeedClient` fetches from the API, `FeedBuilder` emits the
  XML, items link back at `SITE_BASE_URL`). `?q=` filters via `FeedQuery` (a
  server-side port of the frontend search grammar), so feeds inherit the active
  search. Collection pages auto-include `<link rel="alternate">` for both
  formats and a visible feed footer; JS rewrites the feed hrefs with the live
  `?q=`.

### API Data Flow

`FileDataSource` loads JSON files from `api/protected/data/`. Type names map to
filenames with hyphens→underscores (`yoga-objects` → `yoga_objects.json`). Two
JSON formats coexist: keyed dictionaries (`objects.json`, `code.json`) and
arrays (`yoga.json`). All responses are wrapped in `{"data": ..., "meta":
{"count": N, "type": "..."}}`.

HTML partials for individual objects live at
`api/protected/data/objects/{id}/index.html` and are built from dodder via
`just build`.

### Shared Card Rendering & OG Images

Card rendering lives in a local Composer path package, `shared/card-render`
(PSR-4 namespace `Card\`), required by **both** `app/protected` and
`api/protected` as a `"symlink": true` path repo so each `vendor/` entry always
reflects the live source (a `symlink:false` copy silently goes stale — composer
never re-mirrors a fixed-version path package on `install`). `deploy-prod` rsyncs
with `--copy-unsafe-links` to materialize the symlink into a real copy on each
host (which has no `shared/`). It owns the card mustache
templates, `card.css`, `Card\CardRenderer` (data → card HTML, data-driven across
all card types), `Card\Html2Image` (hcti.io client, key injected), and
`Card\OgImage` (card → cached image URL).

The API serves an object's Open Graph image as a **dodder-style format**:
`GET <type>/<id>/blob/formats/og-image` builds the card via `Card\CardRenderer`,
rasterizes it through hcti.io (`Card\OgImage`, request-time, cached by content
hash in `api/tmp/`), and 302-redirects to the image. `GET <type>/<id>/blob/formats`
lists available formats. The hcti key is materialized to
`api/protected/lib/Html2ImageApiKey.php` by `just reveal-secrets`; without it the
route returns a guarded 503. Detail pages emit `<meta property="og:image">`
pointing at this endpoint (see `RouteObject::setOgImage`). Package unit tests run
via `just test-card-render`; the endpoint (formats listing + 503 guard) via
`just test-formats`; and a live, key-gated 302→hcti smoke via
`just test-og-image-live` (skips without the key; networked, not in the hook
gate).

### Frontend Rendering Pipeline

1. `ApiClient` fetches JSON from the API
2. `ZettelParser`/`ApiClient.parseCustomClass()` instantiates model objects
   (Yoga, CodeProject, Zettel, Objekt, Zettel2)
3. Each model's `getHtml($mustache)` does two-phase rendering: card body
   template → `table_card` wrapper
4. Route class merges rendered HTML strings with nav/meta context into a layout
   template

Models share behavior via `FieldMappingTrait` (ID extraction with
`object-id`/`id`/`objectId` fallbacks, search tokenization, URL building,
two-phase HTML rendering).

### Unified Router

`app/private/router.php` serves dual purpose — single route definition array
drives both the PHP built-in dev server and `.htaccess` generation. After
editing routes, regenerate with `just build-htaccess`.

### PHP Autoloading

Both apps use PSR-4 autoloading from their `lib/` directories, loaded via
`auto_prepend_file` in `conf/php.ini`. No namespace prefixes — app/api classes
are in the root namespace. The exception is the shared `shared/card-render`
package, which is namespaced `Card\` and reached via each app's Composer
`vendor/autoload.php`.

### Data Pipeline

Object data originates from dodder (a separate tool). `just build` runs `der`
to export objects as JSON and HTML partials into `api/protected/data/`. Some
data files (yoga, cocktails, slides) are committed directly.

## Testing

Tests use TAP v14 format via bash scripts with curl. `test-router` spins up the
app + API PHP built-in servers, runs 24 HTTP assertions, then tears them down.
`test-feeds` spins up the API + atom servers and asserts the Atom/RSS feeds.
`test-htaccess` validates the generated `.htaccess` against regex patterns
without needing a server.

## Code Style

PHP follows PSR-12 (configured in `.phpcs.xml`, covers `app/protected/` and
`app/public/`).

## Secrets

Secrets live in a repo-local [piggy](https://github.com/amarbel-llc/piggy) store
under `secrets/` — PIV/YubiKey-encrypted `.ebox` files, committed. Run `just
reveal-secrets` to materialize the decrypted PHP class files
(`api/protected/lib/GithubToken.php`, `app/protected/lib/Html2ImageApiKey.php`),
which are gitignored and rsynced to the hosts at deploy. See
`docs/decisions/0003-request-time-readme-read-through.md`.

## Hosting

NearlyFreeSpeech.NET. Deploy scripts run `nfsn web-kick` to force PHP.ini
reload. The `api/conf/php.ini` has `display_errors = Off` to prevent PHP errors
from masking HTTP status codes.
