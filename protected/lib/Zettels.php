<?php declare(strict_types=1);

class Zettels {
  private $path;
  private $zettels;
  private $parser;
  private $site;

  function __construct() {
    $this->path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $this->site = $site ?? $_ENV["SERVER_NAME"] ?? $_SERVER["SERVER_NAME"];

    if (empty($this->site)) {
      $this->site = "linenisgreat.com";
    }

    $prefix = 'www.';

    if (substr($this->site, 0, strlen($prefix)) == $prefix) {
      $this->site = substr($this->site, strlen($prefix));
    }

    $this->setResumeIfNecessary();

    $this->parser = new ZettelParser(
      __DIR__ . "/../../public/{$this->getSiteData()['file']}",
    );
  }

  function isResume() : bool {
    return strcmp($this->site, "sashafriedenberg.com/resume") == 0;
  }

  function setResumeIfNecessary() : void {
    if (strcmp($this->site, "sashafriedenberg.com") != 0) {
      return;
    }

    if (strcmp($this->path, "/resume") != 0) {
      return;
    }

    $this->site = "sashafriedenberg.com/resume";
  }

  function getSite() : string {
    return $this->site;
  }

  function getSiteData() : array {
    switch ($this->getSite()) {
    case "sashafriedenberg.com/resume":

    case "sashafriedenberg.com":
      return [
        "title" => "Sasha Friedenberg",
        "url" => "https://www.sashafriedenberg.com",
        "file" => "cocktails.json",
        "favicon" => "assets/favicon.png",
      ];

    case "isittimetostopworkingyet.com":
      return [
        "title" => "Is It Time to Stop Working Yet?",
        "url" => "https://www.isittimetostopworkingyet.com",
        "file" => "cocktails.json",
        "favicon" => "assets/favicon.png",
      ];

    case "code.linenisgreat.com":
      return [
        "title" => "Linen is Great: Code",
        "url" => "https://www.linenisgreat.com",
        "file" => "code.json",
        "favicon" => "assets/favicon.png",
      ];

    default:
      return [
        "title" => "Linen is Great",
        "url" => "https://www.linenisgreat.com",
        "file" => "zettels.json",
        "favicon" => "assets/favicon.png",
      ];
    }
  }

  function getCodeMetaRaw($mustache) : string {
    if (strcmp($this->getSite(), "code.linenisgreat.com") != 0) {
      return "";
    }

    if (empty($this->path) || strcmp($this->path, "/") == 0) {
      return "";
    }

    $zettel = $this->parser->getRaw()[substr($this->path, 1)] ?? [];

    if (empty($zettel)) {
      // TODO 404
    }

    $code = $zettel['meta'];

    return $mustache->render($code['template'], $code);
  }

  function getMeta($mustache) : array {
    $meta = $this->getSiteData();
    $meta['raw'] = $this->getCodeMetaRaw($mustache);

    return $meta;
  }

  function getCardTemplate() : string{
    switch ($this->getSite()) {
    case "code.linenisgreat.com":
      return "card_code_project";

    default:
      return "cocktail_card";
    }
  }

  function getZettels($mustache) : array {
    if (isset($this->zettels)) {
      return $this->zettels;
    }

    $this->zettels = $this->parser->parse();

    foreach ($this->zettels as $someCocktail) {
      $path = $someCocktail->getLocalPath($mustache);

      if (file_exists($path)) {
        continue;
      }

      $someCocktail->writeToPath($path);
    }

    $this->zettels = array_values($this->zettels);

    return $this->zettels;
  }

  function getCocktailForQuery($query) {
    $matches = explode(",", $query);
    $matches = array_combine($matches, $matches);

    return array_values(
      array_filter(
        $this->getZettels(),
        function ($c) use ($matches) {
          return $c->matches($matches);
        }
    )
    );
  }

  function getCocktailWithId($id) {
    $path = __DIR__ . "/../../tmp/cocktail-$id";

    if (file_exists($path)) {
      return Cocktail::fromPath($path);
    }

    return null;
  }

  function getRandomCocktail() {
    $zettels = $this->getZettels();
    $selected = $zettels[rand(0, count($zettels) - 1)];
    return $selected;
  }

  function getTodayCocktail() {
    $date = date("Y-m-d");
    $path = __DIR__ . "/../../tmp/cocktail-$date";

    if (file_exists($path)) {
      return Cocktail::fromPath($path);
    }

    $selected = $this->getRandomCocktail();
    $selected->writeToPath($selected->getLocalPath());
    symlink($other_path, $path);
    return $selected;
  }
}
