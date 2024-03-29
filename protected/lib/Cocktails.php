<?php declare(strict_types=1);

class Cocktails {
  public $mustache;
  public $cocktails;

  function __construct($mustache) {
    $this->mustache = $mustache;
  }

  function getCocktails() {
    if (isset($this->cocktails)) {
      return $this->cocktails;
    }

    $cocktail_parser = new CocktailParser($this->mustache, __DIR__ . '/../../public/cocktails.json');
    $this->cocktails = $cocktail_parser->parse();

    foreach ($this->cocktails as $someCocktail) {
      $path = $someCocktail->getLocalPath();

      if (file_exists($path)) {
        continue;
      }

      $someCocktail->writeToPath($path);
    }

    shuffle($this->cocktails);
    return $this->cocktails;
  }

  function getCocktailForQuery($query) {
    $matches = explode(",", $query);
    $matches = array_combine($matches, $matches);

    return array_values(
          array_filter(
            $this->getCocktails(),
            function ($c) use ($matches) {
              return $c->matches($matches);
            }
        )
    );
  }

  function getCocktailWithId($id) {
    $path = __DIR__ . "/../../tmp/cocktail-$id";

    if (file_exists($path)) {
      return Cocktail::fromPath($this->mustache, $path);
    }

    return null;
  }

  function getRandomCocktail() {
    $cocktails = $this->getCocktails();
    $selected = $cocktails[rand(0, count($cocktails) - 1)];
    return $selected;
  }

  function getTodayCocktail() {
    $date = date("Y-m-d");
    $path = __DIR__ . "/../../tmp/cocktail-$date";

    if (file_exists($path)) {
      return Cocktail::fromPath($this->mustache, $path);
    }

    $selected = $this->getRandomCocktail();
    $selected->writeToPath($selected->getLocalPath());
    symlink($other_path, $path);
    return $selected;
  }
}
