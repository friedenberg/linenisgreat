---
status: accepted
date: 2026-06-02
---

# Serve /code/<name> READMEs via request-time read-through to GitHub

## Context and Problem Statement

`code.linenisgreat.com/<name>` shows each project's README. Today that README is
a **build-time snapshot**: `build-code-github` (`justfile`) calls
`gh api repos/amarbel-llc/<name>/readme` (GitHub renders the GFM), absolutizes
relative links with `ast-grep`, and writes
`api/protected/data/code/<name>/index.html` (gitignored), which `deploy-prod`
rsyncs. Nothing contacts GitHub at request time, so a README stays stale on prod
until someone re-runs `build-code-github` + `deploy-prod`. Concretely, the
dodder README on prod predated its 2026-05-31 rewrite by months.

We want prod to reflect the live GitHub README without a build/deploy cycle.
ADR-0002 already established — and shipped — request-time outbound cURL from
NFSN to GitHub (the git smart-HTTP proxy, `app/public/code_git_proxy.php`), so
the hosting model supports this.

## Decision Drivers

* **Freshness** — the README on prod should track GitHub without a manual rebuild.
* **Fidelity** — keep GitHub's server-side GFM render (alerts, task lists, syntax
  highlighting); no second-rate local Markdown renderer.
* **Hermetic CI/local** — the `test` gate and local dev must not require network
  or a secret; they already tolerate a missing README partial via the
  description fallback (`app/public/code.php`).
* **Rate limits** — GitHub's unauthenticated REST limit is 60/hr per source IP,
  shared across tenants on NFSN's egress; this argues for authentication.
* **Secret hygiene** — a token must live in a non-web-served location on NFSN
  (`protected/`, per NFSN's site-root guidance) and be encrypted at rest in the
  repo. The existing `git-secret` setup is unreliable here: its
  `.gitsecret/paths/mapping.cfg` entry (`protected/lib/Html2ImageApiKey.php`)
  lost its `app/` prefix in the ADR-0001 app/api split.
* **Scope discipline** — only the README *body* needs to be live; the project
  list (`code.json`) changes rarely and can stay build-time.

## Considered Options

**Rendering upstream:**

* **A — GitHub `/readme` (`Accept: application/vnd.github.html+json`) + token.**
  Exact GitHub GFM render, identical to today's snapshot. Needs a token to
  escape the 60/hr shared-IP limit.
* **A-lite — same endpoint, unauthenticated**, leaning on an aggressive TTL
  cache. No secret, but real rate-limit exposure under shared hosting.
* **B — `raw.githubusercontent.com` + render GFM in PHP** (e.g. league/commonmark).
  No rate limit, no token, but fidelity drift and a new composer dependency.
* **Just refresh the snapshot** — keep build-time, re-run the recipe. Doesn't
  meet the freshness driver.

**Secret store (for Option A's token):**

* **git-secret (status quo)** — gpg-encrypted blob committed in-repo. Its mapping
  is already stale; extending it inherits that breakage.
* **Central piggy store** (`~/.local/share/piggy`) — PIV/YubiKey-encrypted, but a
  separate repo to clone/sync.
* **Repo-local piggy store** — `PIGGY_STORE_DIR` pointed at a committed `secrets/`
  dir; encrypted `.ebox` + public-key recipient template committed in-repo. Same
  "encrypted-in-repo, decrypted at deploy" shape as git-secret, PIV-based.

## Decision Outcome

**Option A (GitHub `/readme` + token), with the token in a repo-local piggy
store, and git-secret retired entirely.**

* The API decorates its `FileDataSource` with **`CodeReadmeDataSource`**, which
  for `code` partials calls **`GithubReadmeClient`** (fetch + TTL cache) and
  falls back to the build-time partial, then to the frontend description card,
  when read-through is unavailable. Relative links are rewritten by
  **`ReadmeLinkAbsolutizer`** (`DOMDocument`, the request-time equivalent of the
  recipe's `ast-grep` rules — element-scoped, so an href that is literal text in
  a code block is never rewritten).
* **Token-gated:** no token configured → the client returns `null` and the live
  path is skipped, so CI/local stay hermetic and prod stays live. Wired via
  `class_exists('GithubToken') ? GithubToken::TOKEN : null` in
  `api/public/index.php`.
* **Caching:** ready-to-serve HTML, atomic temp+rename, stale-on-error fallback
  (the `ApiClient::fetchCached` pattern), in `api/tmp/` (mirrors the
  proven-writable `app/tmp`). Default TTL 6h (`CODE_README_TTL`), layered under
  the frontend's existing 1h `ApiClient` cache. Org via `CODE_GITHUB_ORG`
  (default `amarbel-llc`), mirroring the proxy's `CODE_GIT_UPSTREAM`.
* **Secret store:** a committed `secrets/` piggy store (`PIGGY_STORE_DIR`), holding
  the read-through PAT (`github-readme-token`) and the migrated hcti.io key
  (`html2image-api-key`). `just reveal-secrets` materializes both gitignored PHP
  classes in one PIN/touch (`piggy pass show-batch`). `git-secret` and its
  `.gitsecret/` tree are removed. `secrets/` sits outside the `app/`/`api/` rsync
  source dirs, so encrypted blobs never ship to NFSN; the host only receives the
  decrypted PHP in `protected/`.

Rationale: Option A is the only choice with zero fidelity change from today; the
token cost is acceptable given a repo-local piggy store both fixes the stale
git-secret mapping and matches the repo's existing "encrypted-in-repo" model.
README-only scope keeps `code.json` (and its `build-code-github` build) intact.

### Consequences

* Good: prod READMEs track GitHub within the TTL, no rebuild/deploy needed.
* Good: exact GitHub GFM render preserved; link absolutization matches the recipe.
* Good: secrets unified on piggy/PIV; the broken git-secret mapping is gone.
* Bad: GitHub becomes a request-time dependency for fresh READMEs (mitigated by
  TTL cache + stale-on-error + build-time partial + description fallback).
* Bad: `reveal-secrets` needs a physical YubiKey touch, so the token can't be
  materialized in a fully unattended deploy. Acceptable — a read PAT rotates
  rarely and `deploy-prod` is already human-run.
* Neutral: the footer `readme_updated` keeps its build-time value (a fresh body
  beside a build-time date) to avoid an extra rate-limited API call. Revisitable.

### Confirmation

Implemented in this change. Verified locally (PHP built-in server, no token):

* ✅ `just test-readme-absolutize` — 9 hermetic assertions on the DOMDocument
  rewrite (relative href/img rewritten; absolute / scheme-relative / anchor /
  mailto / data untouched; href literal inside `<code>` untouched; UTF-8 preserved).
* ✅ `just test-code` — API autoloads the new classes, no fatal; the no-token
  fallback renders a README body and the unknown-project fallback is 200.
* ✅ `just test-readme-live` skips cleanly with no token present.

Still to confirm on a real deploy (out of scope for the local work):

* Outbound HTTPS from `api.linenisgreat.com` (ADR-0002's proxy proves it for the
  *app* host; the API host is a separate NFSN site).
* `api/tmp` is writable on the API host (mirrors `app/tmp`).
* With the piggy-revealed token, `just test-readme-live` passes against the live
  dodder README, and the rendered page reflects the post-2026-05-31 rewrite.

## More Information

* Implementation (this change):
  * `api/protected/lib/{ReadmeLinkAbsolutizer,GithubReadmeClient,CodeReadmeDataSource}.php`
  * `api/public/index.php` — wiring + token gate.
  * `secrets/` piggy store; `just reveal-secrets`; `.envrc` `PIGGY_STORE_DIR`.
  * `just test-readme-absolutize` (in `test`), `just test-readme-live` (networked).
* Related: ADR-0002 (request-time GitHub proxy on NFSN — the precedent for
  request-time cURL) and ADR-0001 (app/api split; the source of the stale
  git-secret mapping).
* Out of scope / follow-ups: strip the README-capture block from
  `build-code-github` once read-through is proven; make `readme_updated` live or
  drop it.
* Sources:
  * GitHub READMEs API — <https://docs.github.com/rest/repos/contents#get-a-repository-readme>
  * GitHub REST rate limits — <https://docs.github.com/rest/using-the-rest-api/rate-limits-for-the-rest-api>
  * piggy(1) — <https://github.com/amarbel-llc/piggy>
  * NFSN site root / protected dir — <https://faq.nearlyfreespeech.net/section/programming/siterootphp>
