---
status: accepted
date: 2026-06-07
---

# Events type, framework object footer/feeds, and a feed subdomain (atom.linenisgreat.com)

## Context and Problem Statement

We want an **events** tab: an object filter over the `!event` dodder type — a
TOML/human representation of a CalDAV `VEVENT`. Each event must render as a card
on the index and support the full object view, with an `ics | add to cal`
footer above which sits a "last updated" line; `ics` downloads a file and "add
to cal" opens the visitor's default calendar app. The events index must also
offer a feed (RSS or Atom) that **inherits the active search query**, with the
XML served from `atom.linenisgreat.com` and the page auto-advertising the feed.

The footer (last-updated + format/links), the bottom-of-index feed link, and the
feed itself should be **framework-level** concepts — events is merely the first
consumer — not bespoke events code.

## Decision

**Framework object footer.** `ObjectFooter::build($updated, $links)` +
`object_footer.html.mustache` render a "last updated" line above a
pipe-separated row of `{label, href, download}` links. The per-repo code footer
(github | license) was migrated onto it, retiring `code_footer`; events attach
`ics | add to cal` via the same path. Routes expose it through `meta.footer`
(already the `object.html` slot).

**ICS as a dodder-style format.** The API serves
`GET events/<id>/blob/formats/ics` (`text/calendar`, built by `IcsBuilder` from
the `!event` fields), listed alongside `og-image` under `…/blob/formats`. The
`ics` link hits it over https with `Content-Disposition: attachment` (the HTML
`download` attribute is ignored cross-origin, so the header forces the save);
"add to cal" points at the **same** resource over `webcal://`, which the OS
hands to the default calendar app. One source of truth, works cross-platform.

**Feeds on a standalone `atom/` app.** A third top-level app (mirroring the
app/api split) deploys to `atom.linenisgreat.com` and serves
`GET <type>/feed.atom` and `<type>/feed.rss` for any collection. It is stateless
— `FeedClient` fetches the collection JSON from the API (the same envelope the
frontend consumes), `FeedBuilder` reshapes it into Atom/RSS with item links back
at the human site (`SITE_BASE_URL`). `?q=` filters the feed via `FeedQuery`, a
server-side reimplementation of the frontend search grammar (substring AND, with
`or`/`not`), so opening the feed inherits the active search. The frontend
auto-includes `<link rel="alternate">` for both formats in `<head>` and a
visible feed footer; JS rewrites the feed hrefs with the live query as `?q=`.

## Decision Drivers

- **Framework-level, not events-specific** — footer, feed link, and feed are
  reusable; events is the first consumer (code reuses the footer).
- **Reuse the existing data flow** — the atom app fetches from the API like the
  frontend does, so it owns no data and carries no build step.
- **Single calendar resource** — one ICS endpoint backs both download and
  add-to-cal, differing only by URL scheme.
- **Isolation** — a separate host keeps feed rendering (and any future feed
  growth) off the app and API hosts, consistent with ADR-0001.
- **Hermetic CI** — `test-feeds` starts the API + atom servers locally and
  asserts well-formed XML, one entry per event, site-linked items, and `?q=`
  filtering; no network or secret required.

## Consequences

- A new NFSN site + DNS host `atom.linenisgreat.com` must be provisioned; the
  `atom/` tree, its `composer.json`/`php.ini`/`deploy.sh`, and a `deploy-prod`
  rsync target mirror app/api.
- `og-image` works for events too: `Card\CardRenderer` gained an events →
  `card_event` mapping, so the shared card package stays the single card source.
- `Event::formatWhen` / `Card\CardRenderer::formatWhen` /
  `FeedBuilder::formatWhen` duplicate a tiny date-range humanizer across the
  three apps (none can reach the others' classes); kept in lockstep by comment.
