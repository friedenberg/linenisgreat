<?php declare(strict_types=1);

class Cocktail {
  static function fromPath($mustache, $path) {
    $c = unserialize(file_get_contents($path));
    $c->mustache = $mustache;
    return $c;
  }

  public $mustache;
  public $name;
  public $identifier;
  public $kind;
  public $glass;
  public $garnish;
  public $recipe;
  public $aka;
  public $ingredients;
  public $search_string;
  public $search_array;
  public $html;
  public $css;

  function __construct($mustache, $j) {
    $this->mustache = $mustache;

    $this->identifier = $j['identifier'];
    $this->name = $j['description'];
    $this->kind = $j['kind'] ?? "";
    $this->glass = $j['glass'] ?? "";
    $this->garnish = $j['garnish'] ?? "";

    $this->recipe = $j['recipe'];

    $this->ingredients = array_map(
      function ($i_and_p) {
        return $i_and_p['ingredient'];
      },
      $this->recipe
    );

    $this->search_string = strtolower(implode(" ", $this->ingredients) . " $this->name $this->kind $this->aka");
    $this->search_string = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $this->search_string);
    $this->search_string = trim(preg_replace("/<.*?>/", " ", $this->search_string));
    $this->search_array = preg_split("/[\W]+/", $this->search_string);
    $this->search_array = array_combine($this->search_array, $this->search_array);
  }

  function matches($query) {
    return count(array_intersect($query, $this->search_array)) > 0;
  }

  function image_id() {
    return $this->getId();
  }

  function getHtml() {
    if (!isset($this->html)) {
      $this->html = $this->mustache->render('cocktail_card', $this);
    }

    return $this->html;
  }

  function getCss() {
    if (!isset($this->css)) {
      $this->css = file_get_contents(__DIR__ . '/../../public/stylesheet.css');
    }

    return $this->css;
  }

  function getId() {
    return md5($this->getHtml() . $this->getCss());
  }

  function getLocalPath() {
    return __DIR__ . "/../../tmp/cocktail-{$this->getId()}";
  }

  function getImageUrl() {
    $html = $this->getHtml();
    $css = $this->getCss();
    $md5 = $this->getId();

    $path = __DIR__ . "/../../tmp/cocktail-image-$md5";

    if (file_exists($path)) {
      return file_get_contents($path);
    }

    $html2image = new Html2Image($html, $css);
    $url = $html2image->getImage();

    file_put_contents($path, $url);

    return $url;
  }

  function writeToPath($path) {
    file_put_contents($path, serialize($this));
  }
}
