<?php declare(strict_types=1);

class ZettelParser {
  public $raw;
  public $mustache;
  public $file;

  function __construct($mustache, $path) {
    $this->mustache = $mustache;
    $this->file = file_get_contents($path);
  }

  function getRaw() :array {
    if (isset($this->raw)) {
      return $this->raw;
    }

    $this->raw = json_decode($this->file, true);

    return $this->raw;
  }

  function parse() : array{
    return array_map(
      function ($c) {
        $cocktail = new Zettel($this->mustache, $c);

        return $cocktail;
      },
      $this->getRaw()
    );
  }
}
