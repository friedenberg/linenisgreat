
function updateResults() {
  let stylesheet = document.styleSheets[1];
  let searchBox = document.getElementById("search-box");

  let value = searchBox.value.toLowerCase();
  var rule = null;

  if (value !== "") {
    let query = value.trim().split(" ");
    let mapper = x => `.card-grid > .card:not([data-match*="${x}"])`;
    let attrSelector = query.map(mapper).join(", ");
    rule = `${attrSelector} { display: none; }`;
  }

  for (let i = 0; i < stylesheet.cssRules.length; i++) {
    stylesheet.deleteRule(0);
  }

  if (rule !== null) {
    stylesheet.insertRule(rule, 0);
  }
}
