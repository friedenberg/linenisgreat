// Snappy navigation — progressive enhancement, no framework.
//
// Intercepts same-origin link clicks, fetches the destination page, and swaps
// its `<div class="main-container">` in place of the current one (a pjax /
// "boost" pattern). This skips re-parsing the whole document, re-fetching the
// stylesheets, and re-running scripts, so navigation feels instant while the
// markup stays 100% server-rendered. It degrades cleanly: with JS off, on any
// fetch/parse error, or when the destination has no swap target (e.g. the
// reveal.js slide decks), the browser does a normal full navigation.
//
// See docs/decisions/0004-snappy-navigation-without-htmx.md for why this is a
// ~60-line vanilla script rather than the htmx dependency.
(function () {
  "use strict";

  var SWAP = ".main-container";

  // Return the anchor to boost, or null to let the browser handle the click.
  // We only take over a plain left-click, no modifier keys, on a same-origin
  // link — new-tab / download / external / in-page-anchor clicks pass through.
  function boostable(event) {
    if (
      event.defaultPrevented ||
      event.button !== 0 ||
      event.metaKey ||
      event.ctrlKey ||
      event.shiftKey ||
      event.altKey
    ) {
      return null;
    }

    var a = event.target.closest("a[href]");
    if (
      !a ||
      a.origin !== location.origin ||
      a.hasAttribute("download") ||
      a.target === "_blank" ||
      a.getAttribute("href").charAt(0) === "#"
    ) {
      return null;
    }

    return a;
  }

  // Pull in any `<link rel=stylesheet>` the destination needs that the current
  // head lacks (e.g. resume.css). Appended at the end so the indices of the
  // already-present sheets — which the search code (javascript.js) addresses
  // positionally via document.styleSheets[1] — never shift.
  function mergeStylesheets(nextDoc) {
    var have = {};
    document.head
      .querySelectorAll('link[rel="stylesheet"]')
      .forEach(function (link) {
        have[link.href] = true;
      });
    nextDoc.head
      .querySelectorAll('link[rel="stylesheet"]')
      .forEach(function (link) {
        if (!have[link.href]) {
          document.head.appendChild(link.cloneNode(true));
        }
      });
  }

  function navigate(url, push) {
    // The header lets a future backend skip rendering the outer shell and
    // return just the fragment; today it's harmless and ignored.
    fetch(url, { headers: { "X-Requested-With": "fetch" } })
      .then(function (res) {
        if (!res.ok) {
          throw new Error("HTTP " + res.status);
        }
        return res.text();
      })
      .then(function (html) {
        var nextDoc = new DOMParser().parseFromString(html, "text/html");
        var next = nextDoc.querySelector(SWAP);
        var current = document.querySelector(SWAP);
        if (!next || !current) {
          throw new Error("no swap target");
        }

        mergeStylesheets(nextDoc);
        current.replaceWith(next);
        document.title = nextDoc.title;

        if (push) {
          history.pushState({}, "", url);
          window.scrollTo(0, 0);
        }

        // Reset the search filter/count against the freshly swapped grid.
        if (typeof window.updateResults === "function") {
          window.updateResults();
        }
      })
      .catch(function () {
        // Anything unexpected: hand the URL back to the browser.
        location.href = url;
      });
  }

  document.addEventListener("click", function (event) {
    var a = boostable(event);
    if (!a) {
      return;
    }
    event.preventDefault();
    if (a.href !== location.href) {
      navigate(a.href, true);
    }
  });

  window.addEventListener("popstate", function () {
    navigate(location.href, false);
  });
})();
