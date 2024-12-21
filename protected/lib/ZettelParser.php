<?php declare(strict_types=1);

class ZettelParser {
  public $raw;
  public $file;

  /**
   * @param mixed $path
   */
  function __construct($path) {
    $this->file = file_get_contents($path);
  }

  /**
   * @return <missing>|mixed
   **/
  function getRaw() :array {
    if (isset($this->raw)) {
      return $this->raw;
    }

    $this->raw = json_decode($this->file, true);

    return $this->raw;
  }

  function parse() : array {
    return array_map(
      function ($c) {
        $cocktail = new Zettel($c);

        return $cocktail;
      },
      $this->getRaw(),
    );
  }
}
