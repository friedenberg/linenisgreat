<?php declare(strict_types=1);

class ZettelParser {
  public $raw;
  public $file;
  private $className;

  /**
   * @param string $path
   * @param string $className
   */
  function __construct($path, $className = 'Zettel') {
    $path = realpath($path);

    try {
      $fileContentsOrFalse = file_get_contents($path);
    } catch (Exception $e) {
      $fileContentsOrFalse = false;
    }

    if (!$fileContentsOrFalse) {
      throw new Exception("failed to read object index file: $path");
    }

    $this->file = $fileContentsOrFalse;
    $this->className = $className;
  }

  /**
   * @return array
   **/
  function getRaw() :array {
    if (isset($this->raw)) {
      return $this->raw;
    }

    $this->raw = json_decode($this->file, true);

    return $this->raw;
  }
  /**
   * @param string $urlPrefix
   */
  function parse($urlPrefix = null) : array {
    return array_map(
      function ($c) use ($urlPrefix) {
        if (!is_null($urlPrefix)) {
          $cocktail = new $this->className($c, $urlPrefix);
        } else {
          $cocktail = new $this->className($c);
        }

        return $cocktail;
      },
      $this->getRaw(),
    );
  }

  /**
   * @param string $urlPrefix
   * @param string $className
   */
  function parseCustomClass($className, $urlPrefix = null) : array {
    return array_map(
      function ($c) use ($className, $urlPrefix) {
        if (!is_null($urlPrefix)) {
          $object = new $className($c, $urlPrefix);
        } else {
          $object = new $className($c);
        }

        return $object;
      },
      $this->getRaw(),
    );
  }
}
