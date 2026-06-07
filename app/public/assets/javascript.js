function tokenizeQuery(query) {
  let query_array = query.trim().split(/\s+/);
  return query_array;
}

class NoOpGenerator {
  get negatedSelector() {
    return `.card:not(*)`;
  }

  get selector() {
    return `.card:not(*)`;
  }
}

const noOpGenerator = new NoOpGenerator();

class StringGenerator {
  constructor(value) {
    this.value = value;
  }

  get negatedSelector() {
    return `.card[data-match*="${this.value}"]`;
  }

  get selector() {
    return `.card:not([data-match*="${this.value}"])`;
  }
}

class OrGenerator {
  constructor(left, right) {
    this.left = left;
    this.right = right;
  }

  get leftSelector() {
    let left = "";

    if (this.left !== null) {
      left = this.left.selector;
    }

    return left;
  }

  get rightSelector() {
    let right = "";

    if (this.right !== null) {
      right = this.right.selector;
    }

    return right;
  }

  get selector() {
    return `${this.leftSelector}${this.rightSelector}`;
  }

  get negatedSelector() {
    return `${this.leftSelector}, ${this.rightSelector}`;
  }
}

class NotGenerator {
  constructor(value) {
    this.value = value;
  }

  get negatedSelector() {
    if (this.value === null) {
      return noOpGenerator.negatedSelector;
    }

    return this.value.selector;
  }

  get selector() {
    if (this.value === null) {
      return noOpGenerator.selector;
    }

    return this.value.negatedSelector;
  }
}

function parseQueryTokens(tokens) {
  let withinOr = false;
  const selectorGenerators = [];

  for (const token of tokens) {
    if (token === "or") {
      const lastGenerator = selectorGenerators.pop();
      selectorGenerators.push(new OrGenerator(lastGenerator, null));
    } else if (token === "not") {
      selectorGenerators.push(new NotGenerator(null));
    } else {
      const lastGenerator = selectorGenerators[selectorGenerators.length - 1];
      const newGenerator = new StringGenerator(token);

      if (
        lastGenerator instanceof OrGenerator &&
        lastGenerator.right === null
      ) {
        lastGenerator.right = newGenerator;
      } else if (
        lastGenerator instanceof NotGenerator &&
        lastGenerator.value === null
      ) {
        lastGenerator.value = newGenerator;
      } else {
        selectorGenerators.push(newGenerator);
      }
    }
  }

  return selectorGenerators;
}

function updateResults() {
  let stylesheet = document.styleSheets[1];

  if (stylesheet === undefined) {
    return;
  }

  let searchBox = document.getElementById("search-box");

  let value = searchBox.value.toLowerCase();
  var rule = null;

  if (value !== "") {
    let generators = parseQueryTokens(tokenizeQuery(value));
    let attrSelector = generators
      .map((x) => `.card-grid > ${x.selector}`)
      .join(", ");
    rule = `${attrSelector} { display: none; }`;
  }

  for (let i = 0; i < stylesheet.cssRules.length; i++) {
    stylesheet.deleteRule(0);
  }

  if (rule !== null) {
    stylesheet.insertRule(rule, 0);
  }

  let cards = document.getElementsByClassName("card-contents");
  let count = 0;

  for (const card of cards) {
    if (card.offsetParent === null) {
      continue;
    }

    count++;
  }

  let searchResultCount = document.getElementById("search-result-count");
  searchResultCount.textContent = count;

  // Feed links inherit the active query: the atom/rss hrefs gain a ?q= so the
  // feed (served from atom.linenisgreat.com) is filtered to the same search.
  updateFeedLinks(value);

  // TODO reintroduce-url syncing
  // if (window.history.replaceState) {
  //   const joined = value.trim().split(" ").join(",");
  //   window.history.replaceState(
  //     window.history.statedata,
  //     window.title,
  //     `${window.origin}/${joined}`
  //   );
  // }
}

// Rewrite every .feed-link href to its base (data-feed-base) plus the current
// query as ?q=, so opening the feed inherits the active search filter. An empty
// query restores the bare base.
function updateFeedLinks(value) {
  let query = value.trim();
  let feedLinks = document.getElementsByClassName("feed-link");

  for (const link of feedLinks) {
    let base = link.getAttribute("data-feed-base");

    if (base === null) {
      continue;
    }

    link.href = query === "" ? base : `${base}?q=${encodeURIComponent(query)}`;
  }
}

window.addEventListener("load", function () {
  updateResults();
});
