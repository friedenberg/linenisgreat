# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

Personal portfolio site (linenisgreat.com) split into two independently deployed
PHP applications: a Mustache-templated frontend (`app/`) and a JSON REST API
(`api/`). Both deploy to separate NearlyFreeSpeech.NET SSH hosts via rsync.

## Commands

All commands require the Nix devShell: `nix develop -c just <recipe>`

```sh
just test                # Run all tests (htaccess + router integration)
just test-htaccess       # Validate generated .htaccess rules (11 TAP tests)
just test-router         # Start both servers, test HTTP routes (15 TAP tests)
just build-php-composer  # composer install in both app/ and api/
just build               # Build object data from dodder (requires `der` binary)
just deploy-local        # Run both servers locally (app:2222, api:2223)
just deploy-local-madder # Local app+api+`madder serve`, API proxies /blobs (madder:2224)
just deploy-local-prod-api  # Run app locally against production API
just deploy-prod         # rsync to production + web-kick (requires SSH access)
just build-htaccess   # Regenerate app/public/.htaccess from router.php
```

## Architecture

### Two-Application Split

Both applications share the same `{public,protected,private,conf}` directory
convention expected by NFSN. The `app/` or `api/` prefix is stripped by rsync
during deploy — production sees a flat layout.

- **`app/`** → `linenisgreat.com` — PHP frontend with Mustache templates
- **`api/`** → `api.linenisgreat.com` — stateless JSON API backed by flat files

The frontend connects to the API via `API_BASE_URL` env var (default:
`https://api.linenisgreat.com`). The API accepts `CORS_ORIGIN` env var (default:
`https://linenisgreat.com`).

### API Data Flow

`FileDataSource` loads JSON files from `api/protected/data/`. Type names map to
filenames with hyphens→underscores (`yoga-objects` → `yoga_objects.json`). Two
JSON formats coexist: keyed dictionaries (`objects.json`, `code.json`) and
arrays (`yoga.json`). All responses are wrapped in `{"data": ..., "meta":
{"count": N, "type": "..."}}`.

HTML partials for individual objects live at
`api/protected/data/objects/{id}/index.html` and are built from dodder via
`just build`.

### Madder Blob Reverse Proxy (`/blobs`)

`GET <api>/blobs/<markl-digest>` reverse-proxies to a
[madder](https://github.com/amarbel-llc/madder) `serve` HTTP backend, which
streams a blob's **clear-text** (decompressed, decrypted) bytes by content
address. The proxy is stateless: `MadderClient`
(`api/protected/lib/MadderClient.php`) forwards the request to
`MADDER_BASE_URL` and `ApiResponse::sendBlob` relays the upstream
status/content-type/body, adding CORS and an immutable `Cache-Control` (a
content address never changes). Guards mirror the og-image route: `503` when
`MADDER_BASE_URL` is unset (unavailable, not missing), `400` on a malformed
digest (validated before any network call), `502` when the backend is
unreachable, and a passthrough `404` when madder doesn't hold the blob. The
route is two segments, so it never shadows the three-segment
`<type>/<id>/blob/formats` routes or the item routes.

The backend is `madder serve` (HTTP), the sibling of `madder-mcp serve` (MCP
over stdio); on the madder side it streams `MakeBlobReader` output, so bytes
leave the wire already decompressed. NFSN hosts only PHP, so madder runs
elsewhere — `MADDER_BASE_URL` points at it (a localhost `madder serve` under
`just deploy-local-madder`). Serving **clear text is the first milestone**;
serving ebox/age ciphertext for client-side (wasm) decryption — piggy to
decrypt, madder to decompress — is a planned next step. Guard branches are
covered hermetically by `just test-blobs` (in the `test` gate); the live
round-trip against a real `madder serve` is `just test-blobs-live`
(madder-gated, skips without the binary).

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

Tests use TAP v14 format via bash scripts with curl. `test-router` spins up
both PHP built-in servers, runs 15 HTTP assertions, then tears them down.
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
