
function updateResults() {
  let stylesheet = document.styleSheets[1];
  let searchBox = document.getElementById("search-box");

  let query = searchBox.value;
  let rule = `#search-box[value="${query}"] ~ .card-grid > .card:not([data-match*="${query}"]) { display: none; }`;

  for (let i = 0; i < stylesheet.rules; i++) {
    stylesheet.deleteRule(0);
  }

  if (query !== "") {
    stylesheet.insertRule(rule, 1);
  }
}
