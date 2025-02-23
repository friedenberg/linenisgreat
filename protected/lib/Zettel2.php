<?php declare(strict_types=1);

class Zettel2 {
  public $title;
  public $subtitle;
  public $description;
  public $tags;
  public $objectId;
  public $url;
  public $card_body_template;
  public $icon_css_class;
  public $search_string;
  public $search_array;
  public $html;
  public $css;
  public $card_body;

  /**
   * @param array $j
   * @param string $urlPrefix
   */
  function __construct($j, $urlPrefix = "/") {
    $this->title = $j['title'];
    $this->subtitle = $j['subtitle'];
    $this->description = $j['description'];
    $this->tags = $j['tags'];
    $this->objectId = $j['objectId'];
    $urlSafeTitle = urlencode($this->title);
    $this->url = "$urlPrefix$this->objectId/$urlSafeTitle";

    /* $this->search_string = strtolower(implode(" ", $this->ingredients) . " $this->name $this->kind $this->aka $this->identifier"); */
    /* $this->search_string = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $this->search_string); */
    /* $this->search_string = trim(preg_replace("/<.*?>/", " ", $this->search_string)); */
    /* $this->search_array = preg_split("/[\W]+/", $this->search_string); */
    /* $this->search_array = array_combine($this->search_array, $this->search_array); */

    $this->card_body_template = "card_common";
  }

  /**
   * @param mixed $mustache
   */
  function getHtml($mustache) : string {
    if (!isset($this->html)) {
      $this->card_body = $mustache->render($this->card_body_template, $this);
      $this->html = $mustache->render('table_card', $this);
    }

    return $this->html;
  }

  function getCss() : string {
    if (!isset($this->css)) {
      $this->css = file_get_contents(__DIR__ . '/../../public/assets/stylesheet.css');
    }

    return $this->css;
  }
}
