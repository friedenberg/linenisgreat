<?php declare(strict_types=1);

class CocktailParser {
  function __construct($mustache, $path) {
    $this->mustache = $mustache;
    $this->file = file_get_contents($path);
  }

  function parse() {
    $lines = explode("\n", $this->file);
    $lines = array_slice($lines, 0, count($lines) - 2);

    return array_map(
      function ($line) {
        $tabs = explode("\t", $line);

        $cocktail = new Cocktail(
          $this->mustache,
          $tabs[0],
          $tabs[1],
          $tabs[3],
          $tabs[4],
          $tabs[2],
          null
        );

        return $cocktail;
      },
      $lines
    );
  }
}
