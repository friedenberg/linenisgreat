<?php declare(strict_types=1);

class Cocktail {
  static function fromPath($mustache, $path) {
    $c = unserialize(file_get_contents($path));
    $c->mustache = $mustache;
    return $c;
  }

  function __construct($mustache, $name, $kind, $ingredients, $proportions, $glass, $garnish) {
    $this->mustache = $mustache;
    $this->search_string = strtolower($ingredients . " $name $kind");
    $this->search_string = trim(preg_replace("/<.*?>/", " ", $this->search_string));
    $this->search_array = preg_split("/[\W]+/", $this->search_string);
    $this->search_array = array_combine($this->search_array, $this->search_array);
    $this->name = $name;
    $this->kind = $kind;

    $splitter = function($dirty_string_list) {
      $elements_with_empties = preg_split("/<div>|<\/div>|<br>/", $dirty_string_list);

      return array_filter(
        $elements_with_empties,
        function($element) {
          return !empty($element);
        }
      );
    };

    $this->proportions = $splitter($proportions);
    $this->ingredients = $splitter($ingredients);

    $this->ingredients_and_proportions = array_map(
      function ($p, $i) {
        return ['proportion' => $p, 'ingredient' => $i];
      },
      $this->proportions,
      $this->ingredients
    );

    $this->glass = $glass;
    $this->garnish = $garnish;
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
