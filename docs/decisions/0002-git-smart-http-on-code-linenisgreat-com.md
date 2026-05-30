---
status: accepted
date: 2026-05-30
---

# Serve git smart HTTP at code.linenisgreat.com for canonical flake/git refs

## Context and Problem Statement

Today `code.linenisgreat.com/<name>` works only as a **Go vanity import** host: the
`/code` pipeline emits a `go-import` meta tag that points consumers at
`github.com/amarbel-llc/<name>`. There is no git transport at
`code.linenisgreat.com` itself — and the subdomain currently issues a **302
redirect** to `https://linenisgreat.com/code/<name>` (`app/private/router.php:61-69`),
so any `git`/`nix` client that hit the vanity URL would be bounced to an HTML page.

We want `code.linenisgreat.com/<name>` to also be a **fetchable git endpoint**, so
the vanity domain can act as the canonical git/Nix-flake reference host instead of
exposing `github.com/amarbel-llc/*` as the durable identity. Concretely, a consumer
should be able to write:

```nix
inputs.bob.url = "git+https://code.linenisgreat.com/bob";
```

and `go get code.linenisgreat.com/bob` should keep working against the same URL.

This is an RFC: we are choosing a **direction and a host**, not committing an
implementation. Tracking issue: [#5][issue-5].

## Decision Drivers

* **Canonical identity** — the durable, advertised URL should be ours
  (`code.linenisgreat.com`), decoupled from the current GitHub org so the backing
  store can change (GitHub → dodder) without breaking downstream refs.
* **Nix flake support requires *smart* HTTP** — Nix's `git+https` fetcher does ref
  discovery via `GET …/info/refs?service=git-upload-pack` and expects a real
  upload-pack negotiation; dumb static HTTP is not sufficient for reliable
  `ref`/`rev` resolution.
* **`go get` is *not* a hard constraint** — Go consumers of these modules resolve
  them through the `flake-input-go_mod` feature in `amarbel-llc/{nixpkgs,igloo}`
  (the flake input supplies the module/`go.mod` wiring), **not** via `go get` +
  vanity meta-tag resolution. We are therefore willing to **break `go get`** on
  `code.linenisgreat.com/<name>` if it simplifies the git endpoint. This removes
  the "one URL must serve both protocols" requirement entirely.
* **Hosting fit** — the rest of the site is plain PHP on NearlyFreeSpeech.NET
  (NFSN). Git smart HTTP is a poor fit for NFSN's standard PHP/CGI model; we should
  weigh whether the git endpoint even belongs on NFSN.
* **Cost** — this is a personal portfolio; the solution should cost ~nothing and
  require near-zero operational attention.
* **Read-only** — public fetch/clone only. No push, no auth surface.
* **Dodder pivot** — `build-code-github` (`justfile:74-129`) is explicitly a
  *"TEMPORARY GitHub bridge: dodder is meant to re-become the source of truth …
  at which point the API itself is intended to be served from a dodder repo."*
  Whatever we pick should compose with that future, not fight it.

## Current State (what exists today)

* **Route**: `app/private/router.php:54-58` maps `^code/(\w+)(.+)?$` → `code.php`,
  rendering an HTML page (`RouteObjectOrObjectsIndex`) carrying the meta tag.
* **Meta tag**: `app/protected/lib/templates/code_go_import.html.mustache` —
  `<meta name="go-import" content="{{name}} git {{url}} {{dir}}">`, fed from
  `code.json` `blob.meta` (`RouteObjectOrObjectsIndex.php:57-84`).
* **Data**: `api/protected/data/code.json` — Go projects carry
  `meta.name = "code.linenisgreat.com/<name>"`, `meta.template = "code_go_import"`,
  `meta.url = "https://github.com/amarbel-llc/<name>"`.
* **Redirect blocker**: `app/private/router.php:61-69` 302-redirects all of
  `code.linenisgreat.com/*` to the frontend.
* **No proxy/CGI today**: both `.htaccess` files are pure `mod_rewrite` to local
  PHP. No `ProxyPass`, no CGI, no external-host rewrites.

## Considered Options

The decision has **two axes**: (A) *where* the git endpoint runs, and (B) *what
backs it* (proxy GitHub vs. mirror vs. dodder). Hosting is the binding constraint,
so options below are framed by host.

> **Key clarification — "dumb proxy" vs. "dumb protocol".** GitHub already speaks
> the git *smart* HTTP protocol. A **transparent ("dumb") reverse proxy** that just
> forwards bytes therefore *carries* the smart protocol end-to-end — it does **not**
> downgrade to the dumb git protocol that Nix can't use. So a byte-forwarding proxy
> in front of GitHub satisfies Nix's `git+https` requirement **without** running
> `git-http-backend`, CGI, or any git-aware server. This is what makes keeping the
> literal hostname `code.linenisgreat.com` on NFSN viable.

### Option 0 — PHP cURL reverse proxy on NFSN (keep `code.linenisgreat.com` directly) ⭐

Serve the two smart-HTTP endpoints from a small PHP script inside the **existing
plain-PHP + .htaccess setup** — no Server Type change. A router rule maps
`code/<name>/info/refs` and `code/<name>/git-upload-pack` to a proxy script that
streams the request to `https://github.com/amarbel-llc/<name>.git/…` via cURL and
streams the response back, preserving `Content-Type`
(`application/x-git-upload-pack-advertisement` / `…-result`). Read-only is
structural: only `upload-pack` is ever proxied; `receive-pack` is never wired.

* Good: **`code.linenisgreat.com` is the literal flake host** — exactly the desired
  URL, single vendor, no DNS to a third party.
* Good: stays within the plain-PHP model — **no Server Type change**, ADR-0001
  intact; replaces the 302 with a route, no new infrastructure.
* Good: stateless; zero extra storage; composes with the dodder pivot (later, point
  the proxy's upstream at a dodder-served repo instead of GitHub).
* Bad: **NFSN caps PHP at 3 minutes wall-clock per request** ([phpini FAQ][nfsn-php]).
  Fine for small personal repos (clone in seconds); a genuinely large repo could
  hit the ceiling.
* Bad: must stream correctly — disable output buffering, use
  `CURLOPT_WRITEFUNCTION` + `flush()`, forward `php://input` for the POST body and
  the relevant request/response headers. A naive buffering proxy would balloon
  memory and risk the time cap.
* Bad: GitHub remains a hard runtime dependency (shared with Option 2a).

### Option 1 — NFSN persistent daemon + proxy (keep everything on NFSN)

NFSN's standard CGI cannot host git smart HTTP: CGI/SSH commands are capped at
**2–5 minutes of CPU** and daemon-like processes get killed ([CGI FAQ][nfsn-cgi]).
However, NFSN supports **persistent daemons + proxies** if the site's Server Type
is set to **"Custom"** or **"Apache 2.4 Generic"** ([daemon FAQ][nfsn-daemon]): a
long-lived process listens on a port (1024–65535) via a Run Script, and a Proxy
routes a path prefix to it. That daemon could be `git http-backend` behind a thin
HTTP wrapper, or a reverse proxy to GitHub.

* Good: single vendor; canonical URL is served directly by us.
* Good: composes with the dodder pivot (a dodder-exported bare repo can be served
  by the same daemon later).
* Bad: requires flipping the host's Server Type away from the standard PHP setup —
  a material hosting-model change that contradicts ADR-0001's clean PHP layout.
* Bad: operationally heavier — a process to keep alive, restart, and monitor on a
  host chosen precisely for *not* having long-running processes.
* Bad: NFSN resource billing is per RAU (1 GiB·minute ≈ memory·time); a resident
  daemon bills continuously even when idle (see Cost).

### Option 2 — CNAME `code.linenisgreat.com` (or a sub-path) to an external git host

Split the concern: keep the **vanity HTML + meta tag on NFSN**, and serve the
**git transport from a dedicated host** reachable at the same name (or a delegated
subdomain) via DNS. Sub-variants:

* **2a — Reverse-proxy to GitHub** (Cloudflare Worker or small VPS): proxy
  `…/info/refs` and `…/git-upload-pack` to `github.com/amarbel-llc/<name>.git`.
  Zero storage, always fresh, but GitHub remains a hard runtime dependency. A
  ready example exists: a [Cloudflare Worker git smart-HTTP reverse proxy][gh-proxy].
* **2b — Mirror on a lightweight forge** (Gitea/Forgejo on a small VPS, ~512 MB
  RAM, €4–13/mo): `git clone --mirror` on a cron, serve read-only. Decouples from
  GitHub at the cost of storage + a staleness window ([self-host guide][forgejo]).
* **2c — Edge git server on Cloudflare** ([git-on-cloudflare][git-cf]): full smart
  HTTP v2 served from Workers + R2 + Durable Objects. Powerful but heaviest to
  operate and requires the Workers **paid** plan.

* Good: keeps NFSN as plain PHP — no Server Type change, ADR-0001 stays intact.
* Good: clean separation; the git host can be swapped without touching the site.
* Good (2a): essentially free and stateless.
* Bad: a second moving part and a second vendor/DNS dependency.
* Note: because `go get` is not a constraint (see Decision Drivers —
  `flake-input-go_mod`), the host can serve *only* git smart HTTP. We can CNAME the
  whole of `code.linenisgreat.com` to the git host without needing to also serve the
  go-import meta tag there, or delegate a separate name — either is fine. A
  Cloudflare Worker on a `code.linenisgreat.com` CNAME also avoids NFSN's 3-min PHP
  cap, so it is the natural fallback if Option 0 ever hits that ceiling.

> **Aside — Apache `[P]` proxy flag.** In principle `.htaccess` could proxy with
> `RewriteRule … https://github.com/amarbel-llc/$1.git/… [P]` (plus
> `SSLProxyEngine`), giving an Option-0-like result with no PHP. NFSN says "almost
> any .htaccess directive works" but shared hosting frequently disables proxying to
> an external HTTPS origin; treat this as an unverified shortcut (one support ticket
> to confirm), not the plan ([htaccess FAQ][nfsn-htaccess]). A plain 301 to GitHub
> is rejected: it leaks `github.com` URLs and relies on Nix following redirects
> through smart-HTTP negotiation, which is not dependable.

### Option 3 — Do nothing / dumb static HTTP

Keep the redirect, or publish a static "dumb" git tree (`update-server-info`) on
NFSN's existing static hosting.

* Good: zero new infrastructure.
* Bad: dumb HTTP does not reliably satisfy Nix flake `ref`/`rev` resolution; fails
  the primary driver. The status-quo redirect actively breaks git clients.

## Decision Outcome

**Proposed (not yet committed): Option 0 — PHP cURL reverse proxy to GitHub on
NFSN**, so `code.linenisgreat.com` is itself the flake host — with **Option 2a
(Cloudflare Worker on a `code.linenisgreat.com` CNAME)** as the designated fallback,
and a migration path to a dodder-backed origin once dodder is the source of truth.

Rationale:

* **`code.linenisgreat.com` is the literal flake hostname** — the explicit goal —
  with no third-party DNS and no second vendor in the steady state.
* It satisfies the Nix-flake driver: a transparent proxy carries GitHub's *smart*
  HTTP through unchanged (see the clarification above), so no `git-http-backend`,
  CGI, or daemon is needed.
* It stays inside the **plain-PHP + .htaccess** model — **no Server Type change** —
  preserving ADR-0001; the change is one router rule + one script, replacing the
  302.
* It keeps GitHub as the *current* origin, matching the "temporary GitHub bridge"
  framing in `build-code-github` — when dodder becomes the source of truth, only the
  proxy's upstream changes, not the public URL.
* The one real risk — NFSN's **3-minute wall-clock PHP cap** — is immaterial for
  small personal repos and has a clean escape hatch: flip to Option 2a (Worker on a
  CNAME), which serves the *same* URL without the cap.

Because `go get` resolution is **not** a requirement here — Go consumers go through
`flake-input-go_mod` in `amarbel-llc/{nixpkgs,igloo}`, not vanity meta tags — the
git endpoint is free to serve git smart HTTP and nothing else. This collapses what
was previously an open "shared URL vs. delegated name" sub-decision: we point
`code.linenisgreat.com` straight at the git proxy without preserving the go-import
HTML alongside it.

Two sub-decisions remain **open** and deferred to implementation:

1. **Disposition of the existing `/code` HTML + 302.** `app/private/router.php:61-69`
   currently 302-redirects `code.linenisgreat.com/*` to the frontend, which breaks
   git/flake clients. We must decide whether to (i) hand `code.linenisgreat.com`
   entirely to the git proxy, or (ii) keep human-facing `/code` pages on
   `linenisgreat.com/code/*` and only repoint the bare `code.linenisgreat.com` paths
   that git uses (`info/refs`, `git-upload-pack`). Either is viable now that `go get`
   need not be preserved.
2. **Clone-size headroom.** Confirm the largest repo under `amarbel-llc/*` clones
   well inside NFSN's 3-min PHP cap; if any repo is close, start on Option 2a for
   that name (or all names) instead.

### Consequences

* Good: canonical `git+https://code.linenisgreat.com/<name>` flake refs work, with
  `code.linenisgreat.com` as the literal hostname.
* Good: NFSN stays plain PHP; no daemon, no Server Type change, no second vendor in
  the steady state.
* Good: backing store is swappable (GitHub today, dodder later) behind a stable URL.
* Bad: GitHub remains a hard runtime dependency until/unless a mirror (2b) is added;
  a GitHub outage breaks fetches.
* Bad: subject to NFSN's **3-min wall-clock PHP cap** per request — bounds the
  clonable repo size; mitigated by the Option 2a escape hatch on the same URL.
* Bad: the proxy script must stream carefully (no full buffering) to stay within
  time/memory limits.
* Neutral: `go get code.linenisgreat.com/<name>` may stop resolving. Accepted —
  consumers use `flake-input-go_mod`, not vanity resolution. If a vanity fallback
  is ever wanted again, the go-import HTML can be reinstated on `linenisgreat.com`.

### Confirmation

**Spike completed and accepted** (implemented in this change). The transport is a
PHP cURL proxy at `app/public/code_git_proxy.php`, wired through `router.php` for
both the apex (`/code/<name>/…`) and the literal subdomain bare-path
(`code.linenisgreat.com/<name>/…`) URL shapes, covered by `just test-code-git`.

Verified against a public upstream (`github.com/octocat/Hello-World`) on PHP 8.4:

* ✅ Ref discovery returns HTTP 200 with
  `Content-Type: application/x-git-upload-pack-advertisement`, body opening with the
  `# service=git-upload-pack` pkt-line and the `0000` flush packet.
* ✅ A real `git clone http://code.linenisgreat.com/<name>` (literal host, via host
  mapping) succeeds; cloned HEAD byte-matches what GitHub serves directly — the
  proxy is transparent.
* ✅ Read-only holds: `info/refs?service=git-receive-pack` → 403, and no
  `git-receive-pack` POST route exists.
* ✅ No regression: all 11 `test-htaccess` assertions and all 15 `test-router`
  routes still pass; the human-facing `/code/<name>` page is unaffected.

Still to confirm on a real deploy (out of scope for the local spike):

* `nix flake metadata git+https://code.linenisgreat.com/<name>` on a nix host
  (`nix` is not in this devShell). The clone above exercises the identical
  info/refs + `git-upload-pack` flow Nix's `git+https` fetcher uses, so this is
  expected to pass; the `just test-code-git` comment documents the command.
* Behaviour against the **real NFSN** Apache + 3-min wall-clock cap (the spike used
  PHP's built-in server). Confirm the largest `amarbel-llc/*` repo clones within the
  cap; otherwise move that repo (or all) to the Option 2a Worker fallback.
* A module consumed via `flake-input-go_mod` in `amarbel-llc/{nixpkgs,igloo}` builds
  against the new ref (the actual Go-consumption path).

## Cost Estimate (ballpark)

Order-of-magnitude only; figures from vendor docs/FAQs as of 2026-05 and subject to
change.

| Option | Recurring cost | Notes |
|--------|---------------|-------|
| **0 — PHP cURL proxy on NFSN** | **~$0 (marginal)** | Runs on the existing site; cost is just per-request CPU/bandwidth under NFSN's RAU billing — negligible at portfolio traffic. No new line item, no second vendor. |
| **2a — Cloudflare Worker proxy** | **$0** | Free tier: 100k requests/day; 50 external subrequests/invocation is ample for clone/fetch. Paid tier ($5/mo min) only if volume or subrequest limits are exceeded. ([Workers pricing][cf-pricing]) |
| **1 — NFSN resident daemon** | **~$1–5/mo** | NFSN bills resources per RAU (1 GiB·min ≈ memory·time). A small always-on daemon (~128–256 MB) bills continuously regardless of traffic; storage is $1.00/GiB-month; bandwidth $0.10/GiB if/when charged. ([storage FAQ][nfsn-storage]) A reference data point: four *static* NFSN sites ≈ $3.92/mo. |
| **2b — Gitea/Forgejo VPS mirror** | **~$4–13/mo** | Single Go binary on a 512 MB–4 GB VPS; plus mirror storage. ([self-host guide][forgejo]) |
| **2c — git-on-cloudflare (R2 + DO)** | **$5/mo+** | Requires Workers paid plan; R2/Durable Objects usage on top. ([git-on-cloudflare][git-cf]) |

The recommended Option 0 adds **no new recurring cost**; the Option 2a fallback is
**$0/mo** at portfolio traffic levels.

## Nix flake / Go-consumption technical notes

* Flake ref form: `git+https://<host>/<name>?ref=<branch>` or `?rev=<sha40>` (also
  `?tag=`). `ref` defaults to **HEAD**.
* Nix does not full-clone and the git wire protocol cannot fetch a bare `rev`
  without a known `ref`; a pinned `rev` must be reachable from the advertised
  `ref`/HEAD. → **smart HTTP is mandatory**, dumb HTTP is not enough.
* Gotchas: `ref` is assumed to be a branch (`refs/heads/`), so tags need `?tag=`;
  supplying `ref` *and* `rev` together is rejected by Nix.
* **Go consumption goes through Nix, not `go get`.** The `flake-input-go_mod`
  feature in `amarbel-llc/{nixpkgs,igloo}` wires the module from a flake input, so
  the go-import meta tag is not on the critical path. (Exact mechanism not verified
  in this RFC — those repos are outside this repo's tooling scope; recorded from
  project owner.) This is why we accept breaking `go get` and let the git endpoint
  serve git-only.

## More Information

* Tracking issue: [#5 — Explore git smart HTTP on code.linenisgreat.com][issue-5]
* Implementation (this change):
  * `app/public/code_git_proxy.php` — the streaming read-only cURL proxy.
  * `app/private/router.php` — host-scoped + apex git routes (and `host`/`flags`
    support in the router/.htaccess generator).
  * `just test-code-git` — 6-assertion TAP spike (networked; not in the hook-safe
    `test` gate).
* Related: ADR-0001 (app/api split; NFSN deploy model) — this ADR must not regress
  the plain-PHP layout established there.
* Sources:
  * NFSN CGI limits — <https://faq.nearlyfreespeech.net/q/cgi>
  * NFSN daemons/proxies — <https://faq.nearlyfreespeech.net/full/daemonprocesses>
  * NFSN PHP config / 3-min wall-clock cap — <https://faq.nearlyfreespeech.net/q/phpini>
  * NFSN .htaccess support — <https://faq.nearlyfreespeech.net/q/htaccess>
  * NFSN storage pricing — <https://faq.nearlyfreespeech.net/full/storage>
  * Git http-protocol — <https://git-scm.com/docs/http-protocol>
  * Nix flake refs — <https://github.com/NixOS/nix/blob/master/src/nix/flake.md>
  * Cloudflare Workers pricing/limits — <https://developers.cloudflare.com/workers/platform/pricing/>
  * Cloudflare git smart-HTTP server — <https://github.com/zllovesuki/git-on-cloudflare>

[issue-5]: https://github.com/friedenberg/linenisgreat/issues/5
[nfsn-cgi]: https://faq.nearlyfreespeech.net/q/cgi
[nfsn-daemon]: https://faq.nearlyfreespeech.net/full/daemonprocesses
[nfsn-php]: https://faq.nearlyfreespeech.net/q/phpini
[nfsn-htaccess]: https://faq.nearlyfreespeech.net/q/htaccess
[nfsn-storage]: https://faq.nearlyfreespeech.net/full/storage
[cf-pricing]: https://developers.cloudflare.com/workers/platform/pricing/
[git-cf]: https://github.com/zllovesuki/git-on-cloudflare
[gh-proxy]: https://github.com/neyuki778/gh-proxy
[forgejo]: https://danubedata.ro/blog/self-host-gitea-forgejo-github-alternative-2026
