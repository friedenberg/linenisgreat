---
status: accepted
date: 2026-03-13
---

# Split site into app/ and api/ with separate deploy hosts

## Context and Problem Statement

The repository mixed concerns: the main site's top-level directories
(`public/`, `protected/`, `private/`, `conf/`) cluttered the repo root, and
the API deployed as a subdirectory of the main site (`linenisgreat.com:../api/`)
rather than to its own host. How should we restructure the repo and deployment
targets so each application has a clear boundary?

## Decision Drivers

* The API needs its own SSH host (`api.linenisgreat.com`) with the standard
  `{public,protected,private,conf}` layout expected by NearlyFreeSpeech.NET
* The repo root should contain project infrastructure (justfile, flake.nix,
  docs, config), not application source directories
* Both applications share the same `{public,protected,private,conf}` directory
  convention, so the repo structure should reflect that symmetry
* PHP files use `__DIR__`-relative paths, so moving them must not break internal
  requires

## Considered Options

* **Option A: app/ and api/ subdirectories with separate hosts** — move the
  main site into `app/`, keep `api/` as-is, deploy each to its own SSH host
* **Option B: keep flat layout, only change API deploy target** — update the
  rsync command but leave directories at the repo root
* **Option C: monorepo with separate git subtrees** — split app and api into
  independent git histories

## Decision Outcome

Chosen option: "app/ and api/ subdirectories with separate hosts", because it
gives both applications identical structure, keeps the repo root clean, and
requires only path-prefix changes (no code logic changes) since PHP uses
`__DIR__`-relative paths internally.

### Consequences

* Good, because the repo root now contains only infrastructure files — the
  two applications are clearly delineated under `app/` and `api/`
* Good, because rsync of `app/{public,protected,private,conf}` to
  `linenisgreat.com:../` preserves the flat layout the host expects (rsync
  uses the basename of each source path)
* Good, because the API gets proper `private/` and `conf/` directories for
  future server-side scripts and configuration
* Bad, because every justfile recipe path needed an `app/` prefix, increasing
  the diff surface of this change
* Neutral, because production remote paths (`../private/deploy.sh`,
  `nfsn web-kick`) are unchanged — the host filesystem layout is the same

### Confirmation

Run the verification steps below after any change to directory structure or
deploy recipes.

## Troubleshooting and Validation

### Quick smoke test

```sh
# Validate .htaccess generation (runs php against app/private/router.php)
nix develop -c just test-htaccess

# Run integration tests against local PHP servers
nix develop -c just test-router
```

Both commands must pass before deploying.

### Verify rsync targets (dry-run)

```sh
# Main site — should show public/, protected/, private/, conf/ at remote root
rsync -rnv \
  --include ".htaccess" --delete --exclude ".*" \
  app/public app/protected app/private app/conf \
  linenisgreat.com:../

# API — should show public/, protected/, private/, conf/ at remote root
rsync -rnv \
  --include ".htaccess" --delete --exclude ".*" \
  api/public api/protected api/private api/conf \
  api.linenisgreat.com:../
```

Check that the dry-run output creates the expected flat directory names on
the remote (e.g., `public/`, not `app/public/`).

### Common issues

| Symptom | Likely cause | Fix |
|---------|-------------|-----|
| `test-htaccess` fails with "file not found" | A path in `justfile` or `test-htaccess.sh` still references `private/` instead of `app/private/` | Grep for bare `private/` or `public/` without `app/` or `api/` prefix in justfile and shell scripts |
| PHP "failed to open stream" | A `require` or `include` uses a repo-root-relative path instead of `__DIR__` | Fix the PHP file to use `__DIR__ . '/../...'` relative paths |
| rsync creates `app/public/` on remote | Source path has a trailing slash (`app/public/` copies contents) or the full `app/` directory was passed instead of individual subdirs | Ensure rsync sources are `app/public` (no trailing slash) so rsync copies the `public` directory by basename |
| `.gitignore` not matching | Ignored paths still use old prefixes (e.g., `protected/vendor/` instead of `app/protected/vendor/`) | Update `.gitignore` entries to include `app/` prefix |
| Deploy script fails on remote | `ssh linenisgreat.com ../private/deploy.sh` — the remote `private/` directory must contain `deploy.sh` | Verify the main site rsync completed successfully before the ssh step |

### Verifying a new file is correctly placed

If adding a new file to either application:

1. **Repo path:** place under `app/` or `api/` as appropriate
2. **Justfile reference:** use `app/` or `api/` prefix
3. **PHP internal path:** use `__DIR__`-relative — no repo root references
4. **Production path:** unchanged — rsync strips the `app/` or `api/` prefix

## More Information

Implemented in two commits on the `mild-elm` branch:

1. `7403952` — Point API rsync at `api.linenisgreat.com`, add `api/{private,conf}`
2. `45ca7d3` — Move `{public,protected,private,conf}` into `app/`, update all
   path references in justfile, .gitignore, .phpcs.xml, .phpactor.json,
   intelephense.json, and test-htaccess.sh
