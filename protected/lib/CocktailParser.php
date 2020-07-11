<?php declare(strict_types=1);

class CocktailParser {
  function __construct($path) {
    $this->file = file_get_contents($path);
  }

  function parse() array {
    $lines = explode("\n", $this->file);

    return array_map(
      function ($line) {
        $cocktail = [];
        $tabs = explode("\t", $line);
        $cocktail['name'] = $tabs[0];
        $cocktail['kind'] = $tabs[1];
        $cocktail['ingredients'] = $tabs[2];
        $cocktail['proportions'] = $tabs[3];
        return $cocktail;
      },
      $lines
    );
  }
}
