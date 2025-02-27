<?php declare(strict_types=1);

// TODO inherit from Route
class RouteObjectOrObjectsIndex {
  public $nav;
  public $mustache;
  public $title;

  private $objects;
  private $objectId;

  /**
   * @param ZettelParser $parser
   */
  private $parser;

  /**
   * @param string $title
   */
  function __construct(
    $title,
    $objectId = null,
  ) {
    $this->nav = new Nav($title);
    $this->title = $title;
    $this->objectId = $objectId;
    $objectsFile = __DIR__ . "/../../public/$title.json";
    $this->parser = new ZettelParser($objectsFile);

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

  function getCodeMetaRaw() : string {
    if (strcmp($this->title, "code") != 0) {
      return "";
    }

    if (is_null($this->objectId)) {
      return "";
    }

    $raw = $this->parser->getRaw();
    $object = [];

    foreach ($raw as $someZettel) {
      if (strcmp($someZettel['akte']['kennung'], $this->objectId) === 0 ) {
        $object = $someZettel;
        break;
      }
    }

    if (empty($object)) {
      return "";
    }

    $code = $object['akte']['meta'];

    return $this->mustache->render($code['template'], $code);
  }

  /**
   * @return array<string,string>
   */
  function getMeta() : array {
    $meta = $this->getSiteData();
    $meta['raw'] = $this->getCodeMetaRaw($this->mustache);

    return $meta;
  }

  function getObjects($objectClassName, $urlPrefix) : array {
    if (isset($this->objects)) {
      return $this->objects;
    }

    $this->objects = $this->parser->parseCustomClass($objectClassName, $urlPrefix);

    /* foreach ($this->objects as $object) { */
    /*   $path = $object->getLocalPath($this->mustache); */

    /*   if (file_exists($path)) { */
    /*     continue; */
    /*   } */

    /*   $object->writeToPath($path); */
    /* } */

    $this->objects = array_values($this->objects);

    return $this->objects;
  }

  private function makeTemplateArgs(...$extra) : array {
    return array_merge(
      [
        'nav' => array_values($this->nav->tiles),
        'meta' => $this->getMeta(),
        'stylesheets' => [
          "stylesheet",
          "zettels",
          "fonts",
        ],
      ],
      ...$extra,
    );
  }

  function renderIndex(
    $template,
    $objectClassName,
    $urlPrefix,
    $args = [],
  ) : void {
    $mustache = $this->mustache;

    echo $this->mustache->render(
      $template,
      $this->makeTemplateArgs(
        [
          'objects' => array_map(
            function ($object) use ($mustache) {
              return $object->getHtml($mustache);
            },
            $this->getObjects($objectClassName, $urlPrefix),
          ),
        ],
        $args,
      ),
    );
  }

  function renderObject($template, $args) : void {
    echo $this->mustache->render(
      $template,
      $this->makeTemplateArgs($args),
    );
  }
}
