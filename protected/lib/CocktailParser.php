<?php declare(strict_types=1);

class CocktailParser {
  function __construct($mustache, $path) {
    $this->mustache = $mustache;
    $this->file = file_get_contents($path);
  }

  function parse() {
    $raw_cocktails = json_decode($this->file, true);

    return array_map(
      function ($c) {
        $cocktail = new Cocktail($this->mustache, $c);

        return $cocktail;
      },
      $raw_cocktails
    );
  }
}
