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
`auto_prepend_file` in `conf/php.ini`. No namespace prefixes — classes are in
the root namespace.

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

Secrets are managed via `git-secret`. Encrypted files (`.secret` extension) are
committed; decrypted files are gitignored.

## Hosting

NearlyFreeSpeech.NET. Deploy scripts run `nfsn web-kick` to force PHP.ini
reload. The `api/conf/php.ini` has `display_errors = Off` to prevent PHP errors
from masking HTTP status codes.
