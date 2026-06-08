---
status: accepted
date: 2026-06-08
---

# Snappier in-page navigation, rolled by hand instead of adopting htmx

## Context and Problem Statement

The frontend (`app/`) is a server-rendered PHP/Mustache site. Every section
(`yoga`, `code`, `objects`, `notes`, `slides`, `cocktails`, `resume`, `meet`,
the `about` home) is reached by a plain `<a href>` and served as a full document
via the unified router (`app/private/router.php` → `app/public/*.php`). Moving
between sections therefore triggers a full page reload: re-parse the document,
re-fetch and re-parse the stylesheets, re-run scripts, flash, scroll reset.

The pages are small and the API responses are cached (`ApiClient`, 1h), so the
*bytes* are cheap — but the per-navigation browser teardown/rebuild is the felt
cost. We want navigation to feel instant without changing the rendering model or
adding a build step (the site deploys to NearlyFreeSpeech.NET by rsync, no
bundler, no npm).

The prompt was specifically to evaluate **htmx** for this, prompted by David
Bushell's "HTMX Is So Cool I Rolled My Own!"
(<https://dbushell.com/2024/04/16/htmx-and-modern-javascript/>), whose thesis is
that htmx's *pattern* — server-rendered HTML over the wire, progressive
enhancement — is excellent, but for modest needs the library itself is an
optional dependency that a small amount of modern vanilla JS replaces.

## Decision Drivers

- **Perceived speed** — section-to-section and card-to-detail navigation should
  feel immediate, with no flash or scroll reset.
- **Keep the rendering model** — markup stays 100% server-rendered Mustache; no
  move to client-side templating or JSON-driven views.
- **No build step** — the NFSN deploy is rsync of plain PHP/CSS/JS. Anything
  added must be a static asset, not a compiled artifact.
- **Graceful degradation** — links must keep working with JS off or on error;
  this is a portfolio, crawlers and no-JS clients must get the full page.
- **Match the repo's ethos** — the existing client JS is ~180 lines of
  dependency-free vanilla (`assets/javascript.js`, the client-side search).
- **Small dynamic surface** — there are no forms, no mutations, no auth, no
  server-side session state. The only interactive features are the client-side
  search and navigation.

## Considered Options

- **A — Adopt htmx.** Add the `<script>` tag, mark links `hx-boost`, swap a
  target, `hx-push-url`. To avoid shipping a full layout per request we'd also
  add server-side fragment rendering (detect the `HX-Request` header in the
  `Route`/`RouteObject` render path and emit just the inner partial).
- **B — Roll our own "boost"** (this option). A ~60-line vanilla script that
  intercepts same-origin link clicks, `fetch`es the destination, parses it with
  `DOMParser`, and swaps the existing `.main-container` for the destination's,
  updating `document.title` and the History API. No backend change.
- **C — Do nothing.** Full reloads. Cheapest, but doesn't meet the speed driver.
- **D — Full SPA / client-side router** (e.g. a JS framework). Rejected outright:
  it discards the SSR model, needs a build step, and is wildly out of proportion
  to a static portfolio.

## Decision Outcome

**Option B — roll our own boost.** Bushell's conclusion applies almost verbatim:
the dynamic surface here is a client-side search (already best-in-class for this
site *without* htmx — it filters pre-rendered cards via an injected CSS rule, no
network round-trip) plus navigation. htmx's strongest use case — forms and
post-then-swap mutations — is entirely absent. For navigation alone, htmx would
mean taking on a dependency *and* the `HX-Request` fragment refactor, to get
what ~60 lines of vanilla delivers with no backend change.

Implementation (this change):

- **`app/public/assets/nav.js`** — the boost script. Left-click on a plain
  same-origin link → `fetch` the page, `DOMParser` it, swap `.main-container`,
  set `document.title`, `history.pushState`, reset scroll. `popstate` re-runs
  the same swap. Any failure (non-OK response, no swap target, parse error,
  modified click, external/download/`_blank`/anchor link) falls through to a
  normal browser navigation. Stylesheets the destination needs but the current
  head lacks (e.g. `resume.css`) are appended to the head; they're appended at
  the **end** so the positional `document.styleSheets[1]` reference in the
  search code never shifts.
- **Scripts moved into the shared `head` partial.** `javascript.js` previously
  loaded (async, in `<body>`) only on the listing templates (`common`,
  `index`); detail pages (`object`, `meet`) shipped no JS at all, so a boost
  couldn't originate from them. Both `javascript.js` and `nav.js` now load
  `defer` from `head.html.mustache`, so every `{{> head}}` page (about, common,
  index, meet, object) gets both, and the duplicate `<body>` tags were removed
  from `common`/`index`.
- **`updateResults()` guarded.** Now that the search script loads on detail
  pages too, `updateResults()` returns early when there's no `#search-box`
  instead of dereferencing null. `nav.js` calls it after each swap to reset the
  filter/count against the freshly swapped grid.

The slide decks (`slide.html.mustache`) are reveal.js full-screen documents with
their own scripts and no `.main-container`; they intentionally fall through to a
full navigation (the boost finds no swap target and reloads), which is correct.

### Consequences

- Good: section and card→detail navigation are swaps, not reloads — no flash,
  no stylesheet re-fetch, no scroll jump, script state preserved across nav.
- Good: zero backend change, zero build step, zero runtime dependency; one new
  static file in the same hand-rolled, dependency-free style as the search.
- Good: fully progressive — JS-off clients, crawlers, and any error path get the
  unchanged full server-rendered page.
- Neutral / limitation: the boost fetches the **full** destination document and
  discards its outer shell. Cheap today (small, cached pages); the real win is
  skipping the browser-side teardown, not bytes. `nav.js` already sends
  `X-Requested-With: fetch`, so if payloads ever grow, a backend fragment mode
  (render only the inner partial when that header is present — the htmx
  `HX-Request` idea, which the two-phase Mustache pipeline makes
  straightforward) is a drop-in optimization.
- Limitation: only `document.title` is updated on a swap; other `<head>` tags
  (`og:image`, canonical) are not. Acceptable — social/unfurl crawlers fetch the
  URL directly and get the complete SSR head; only in-session human navigation
  uses the swap. Revisitable if it matters.
- Bad: one more positional coupling to live with — `nav.js` appends merged
  stylesheets at the end of `<head>` specifically to protect the search code's
  `document.styleSheets[1]`. Both are noted at their site.

### Confirmation

- `just test` (htaccess + router integration) stays green — the router tests
  assert status codes and the og:image meta, neither of which this change
  touches (the moved `<script>` and new asset don't affect routing or og tags).
- Manual: `just deploy-local`, then navigate between sections and from a card to
  its detail page — content swaps without a reload, the URL and title update,
  Back/Forward work, search still filters, and disabling JS (or hitting a slide
  deck) falls back to full navigation.

## More Information

- Implementation (this change):
  - `app/public/assets/nav.js` — the boost script.
  - `app/public/assets/javascript.js` — `updateResults()` no-search-box guard.
  - `app/protected/lib/templates/head.html.mustache` — deferred script tags.
  - `app/protected/lib/templates/{common,index}.html.mustache` — removed the
    now-redundant in-body script tag.
- Out of scope / follow-ups: a backend fragment mode keyed on the
  `X-Requested-With`/`HX-Request` header; updating `og:*`/canonical head tags on
  swap; a small loading indicator for slow fetches.
- Related: ADR-0001 (app/api split; the SSR frontend this enhances).
- Sources:
  - htmx — <https://htmx.org/>
  - "HTMX Is So Cool I Rolled My Own!", David Bushell —
    <https://dbushell.com/2024/04/16/htmx-and-modern-javascript/>
  - History API / pushState —
    <https://developer.mozilla.org/en-US/docs/Web/API/History_API>
