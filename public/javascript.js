
function updateResults() {
  let stylesheet = document.styleSheets[1];
  let searchBox = document.getElementById("search-box");

  let query = searchBox.value;
  let rule = `#search-box[value="${query}"i] ~ .card-grid > .card:not([data-match*="${query}"i]) { display: none; }`;

  for (let i = 0; i < stylesheet.rules; i++) {
    stylesheet.deleteRule(0);
  }

  if (query !== "") {
    stylesheet.insertRule(rule, 1);
  }
}
