<?php declare(strict_types=1);

class Tab {
  public $mustache;
  public $title;

  private $zettels;
  private $parser;
  private $site;
  /**
   * @param string $title
   * @param string $objectFile
   */
  function __construct(
    $title,
    $objectFile = null,
  ) {
    $this->title = $title;

    if (!is_null($objectFile)) {
      $this->parser = new ZettelParser( "$objectFile");
    }

    $options = array('extension' => '.html.mustache');

    $this->mustache = new Mustache_Engine(array(
      'loader' => new Mustache_Loader_FilesystemLoader(
        __DIR__ . '/templates',
        $options,
      ),
      'entity_flags' => ENT_QUOTES,
    ));
  }

  /**
   * @return array<string,string>
   */
  function getSiteData() : array {
    return [
      "title" => $this->title,
      "favicon" => "assets/favicon.png",
    ];

  }
  /**
   * @param mixed $mustache
   */
  function getCodeMetaRaw($mustache) : string {
    if (strcmp($this->title, "code") != 0) {
      return "";
    }

    if (empty($this->path) || strcmp($this->path, "/") == 0) {
      return "";
    }

    $elements = explode("/", $this->path);
    $key = $elements[1];
    $raw = $this->parser->getRaw();
    $zettel = [];

    foreach ($raw as $someZettel) {
      if (strcmp($someZettel['akte']['kennung'], $key) === 0 ) {
        $zettel = $someZettel;
        break;
      }
    }

    /* $zettel = $raw[$key] ?? []; */

    if (empty($zettel)) {
      // TODO 404
    }

    $code = $zettel['akte']['meta'];
    /* $remainder = implode("/", array_slice($elements, 2)); */
    /* $code['name'] = $code['name'] . '/' . $remainder; */
    /* $code['url'] = $code['url'] . $remainder; */

    return $mustache->render($code['template'], $code);
  }

  /**
   * @return array<string,string>
   */
  function getMeta() : array {
    $meta = $this->getSiteData();
    $meta['raw'] = $this->getCodeMetaRaw($this->mustache);

    return $meta;
  }

  function getCardTemplate() : string{
    switch ($this->title) {
    case "code":
      return "card_code_project";

    default:
      return "cocktail_card";
    }
  }
  /**
   * @return <missing>|array@param mixed $mustache
   */
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
  /**
   * @param mixed $query
   */
  function getCocktailForQuery($query): array {
    $matches = explode(",", $query);
    $matches = array_combine($matches, $matches);

    return array_values(array_filter(
      $this->getZettels(),
      function ($c) use ($matches) {
        return $c->matches($matches);
      }
    ));
  }

  /**
   * @param mixed $id
   * @return <missing>|null
   */
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

  function render($template, $args) : void {
    $this->mustache->render($template, $args);
  }
}
