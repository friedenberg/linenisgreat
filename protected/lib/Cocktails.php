<?php declare(strict_types=1);

class Cocktails {
  function __construct($mustache) {
    $this->mustache = $mustache;
  }

  function getCocktails() {
    if (isset($this->cocktails)) {
      return $this->cocktails;
    }

    $cocktail_parser = new CocktailParser($this->mustache, __DIR__ . '/../../public/cocktails.txt');
    $this->cocktails = $cocktail_parser->parse();
    return $this->cocktails;
  }

  function getTodayCocktail() {
    $date = date("Y-m-d");
    $path = __DIR__ . "/../../tmp/cocktail-$date";

    if (file_exists($path)) {
      return Cocktail::fromPath($this->mustache, $path);
    }

    $cocktails = $this->getCocktails();
    $selected = $cocktails[rand(0, count($cocktails) - 1)];
    $selected->writeToPath($path);
    return $selected;
  }
}
