<?php declare(strict_types=1);

class Yoga {
  public $headers;
  public $fields;

  public $title;
  public $subtitle;
  public $type;
  public $description;
  public $objectId;
  public $date;
  public $meta;
  public $card_body_template;
  public $icon_css_class;
  public $search_string;
  public $search_array;
  public $html;
  public $css;
  public $card_body;
  public $url;

  public $duration;

  /**
   * @param array $j
   * @param string $urlPrefix
   */
  function __construct($j, $urlPrefix = "") {
    $this->title = $j['title'];
    $this->subtitle = $j['duration'];
    $this->type = $j['type'];
    $this->duration = $j['duration'];
    $this->description = $j['description'];
    $this->date = $j['date'];
    $this->objectId = $j['object-id'] ?? $j['id'] ?? "";

    $this->card_body_template = "card_object_new";
    $titleUrlEncoded = urlencode($this->title);
    $this->url = "$urlPrefix$this->objectId/$titleUrlEncoded";

    $tags = implode(", ", $j['tags']);

    $this->headers = [
      [
        'classes' => "text-center title uppercase",
        'value' => $this->title,
      ],
      [
        'classes' => "text-center small-caps text-small",
        'value' => "id: $this->objectId",
      ],
    ];

    $tags = implode(", ", $j['tags']);

    $this->fields = [];

    if (isset($this->duration)) {
      array_push(
        $this->fields,
        [
          'key' => "duration",
          'value' => $this->duration,
        ],
      );
    }

    if (isset($this->level)) {
      array_push(
        $this->fields,
        [
          'key' => "level",
          'value' => $this->level,
        ],
      );
    }

    if (isset($this->intensity)) {
      array_push(
        $this->fields,
        [
          'key' => "intensity",
          'value' => $this->intensity,
        ],
      );
    }

    if (isset($tags)) {
      array_push(
        $this->fields,
        [
          'key' => "tags",
          'value' => $tags,
        ],
      );
    }

    $this->search_string = "$this->title $this->subtitle $this->duration $this->objectId $this->description $this->type";
    $this->search_string .= "{$j['level']} {$j['intensity']} {$tags}";

    // TODO refactor below into card parent class
    $this->search_string = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $this->search_string);
    $this->search_string = trim(preg_replace("/<.*?>/", " ", $this->search_string));
    $this->search_string = trim(preg_replace("/\W+/", " ", $this->search_string));

    $this->search_string .= "d-$this->duration l-{$j['level']} i-{$j['intensity']} {$tags}";
    $this->search_string = strtolower($this->search_string);

    $this->search_array = preg_split("/[\W]+/", $this->search_string);
    $this->search_array = array_combine($this->search_array, $this->search_array);
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
