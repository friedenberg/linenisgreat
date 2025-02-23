<?php declare(strict_types=1);

class Zettel {
  static function fromPath($path) {
    $c = unserialize(file_get_contents($path));
    return $c;
  }

  public $typ;
  public $meta;
  public $card_body_template;
  public $icon_css_class;
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
  public $card_body;
  public $description;
  public $url;

  function __construct($j) {
    $this->typ = $j['typ'] ?? "toml-cocktail";
    $this->description = $j['bezeichnung'] ?? "";
    $this->name = $j['bezeichnung'];
    $this->identifier = $j['kennung'];

    $data = $j['akte'];

    if (is_string($data)) {
      $this->description = $data;
    }

    if (!empty($data['kennung'])) {
      $this->identifier = $data['kennung'];
    }

    $this->meta = $data['meta'] ?? [];
    $this->kind = $data['kind'] ?? "";
    $this->glass = $data['glass'] ?? "";
    $this->garnish = $data['garnish'] ?? "";

    $this->recipe = $data['recipe'] ?? [];

    $this->ingredients = array_map(
      function ($i_and_p) {
        return $i_and_p['ingredient'];
      },
      $this->recipe
    );

    $this->search_string = strtolower(implode(" ", $this->ingredients) . " $this->name $this->kind $this->aka $this->identifier");
    $this->search_string = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $this->search_string);
    $this->search_string = trim(preg_replace("/<.*?>/", " ", $this->search_string));
    $this->search_array = preg_split("/[\W]+/", $this->search_string);
    $this->search_array = array_combine($this->search_array, $this->search_array);

    switch ($this->typ) {
    case "toml-project-code":
      $this->card_body_template = "card_code_project";
      $this->url = "code/$this->identifier";
      break;

    case "md":
      $this->card_body_template = "card_code_project";
      $this->url = "code/$this->identifier";
      break;

    default:
      $this->url = "cocktails/$this->identifier";
      $this->card_body_template = "cocktail_card";
      $this->icon_css_class = "toml-cocktail-$this->glass";
    }
  }

  function matches($query) {
    return count(array_intersect($query, $this->search_array)) > 0;
  }

  function image_id($mustache) {
    return $this->getId($mustache);
  }

  function getHtml($mustache) {
    if (!isset($this->html)) {
      $this->card_body = $mustache->render($this->card_body_template, $this);
      $this->html = $mustache->render('table_card', $this);
    }

    return $this->html;
  }

  function getCss() {
    if (!isset($this->css)) {
      $this->css = file_get_contents(__DIR__ . '/../../public/assets/stylesheet.css');
    }

    return $this->css;
  }

  function getId($mustache) {
    return md5($this->getHtml($mustache) . $this->getCss());
  }

  function getLocalPath($mustache) {
    return __DIR__ . "/../../tmp/cocktail-{$this->getId($mustache)}";
  }

  function getImageUrl($mustache) {
    $html = $this->getHtml($mustache);
    $css = $this->getCss();
    $md5 = $this->getId($mustache);

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
