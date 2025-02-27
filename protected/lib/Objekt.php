<?php declare(strict_types=1);

class Objekt {
  public $type;
  public $meta;
  public $card_body_template;
  public $icon_css_class;
  public $name;
  public $objectId;
  public $date;
  public $search_string;
  public $search_array;
  public $html;
  public $css;
  public $card_body;
  public $description;
  public $url;

  /**
   * @param array $j
   * @param string $urlPrefix
   */
  function __construct($j, $urlPrefix = "") {
    $this->type = $j['type'];
    $this->description = $j['description'] ?? "";
    $this->date = $j['date'];
    $this->objectId = $j['object-id'];

    /* $data = $j['blob-string']; */

    /* if (is_string($data)) { */
    /*   $this->description = $data; */
    /* } */

    /* if (!empty($data['object-id'])) { */
    /*   $this->title = $data['object-id']; */
    /* } */

    /* $this->meta = $data['meta'] ?? []; */

    $this->search_string = "$this->name $this->objectId";
    $this->search_string = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $this->search_string);
    $this->search_string = trim(preg_replace("/<.*?>/", " ", $this->search_string));
    $this->search_array = preg_split("/[\W]+/", $this->search_string);
    $this->search_array = array_combine($this->search_array, $this->search_array);
    $this->card_body_template = "card_object";
    $this->url = "$urlPrefix$this->objectId/$this->description";
  }

  /**
   * @param mixed $mustache
   */
  function getHtml($mustache): string {
    if (!isset($this->html)) {
      $this->card_body = $mustache->render($this->card_body_template, $this);
      $this->html = $mustache->render('table_card', $this);
    }

    return $this->html;
  }

  function getCss(): string|false {
    if (!isset($this->css)) {
      $this->css = file_get_contents(__DIR__ . '/../../public/assets/stylesheet.css');
    }

    return $this->css;
  }
}
