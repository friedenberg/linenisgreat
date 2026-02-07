<?php

declare(strict_types=1);

class Yoga
{
    use FieldMappingTrait;
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
    public function __construct($j, $urlPrefix = "")
    {
        $this->title = $j['title'];
        $this->subtitle = $j['duration'];
        $this->type = $j['type'];
        $this->duration = $j['duration'];
        $this->description = $j['description'];
        $this->date = $j['date'];
        $this->objectId = $this->extractObjectId($j);

        $this->card_body_template = "card_object_new";
        $this->url = $this->buildUrl($urlPrefix, $this->objectId, $this->title);

        $tags = $this->normalizeTags($j['tags'] ?? []);

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

        $this->fields = [];

        if (isset($this->duration)) {
            $this->fields[] = ['key' => "duration", 'value' => $this->duration];
        }

        if (isset($this->level)) {
            $this->fields[] = ['key' => "level", 'value' => $this->level];
        }

        if (isset($this->intensity)) {
            $this->fields[] = ['key' => "intensity", 'value' => $this->intensity];
        }

        if (!empty($tags)) {
            $this->fields[] = ['key' => "tags", 'value' => $tags];
        }

        // Build search string with structured prefixes for filtering
        $baseSearch = "$this->title $this->subtitle $this->duration $this->objectId $this->description $this->type";
        $baseSearch .= " {$j['level']} {$j['intensity']} {$tags}";
        $prefixedSearch = "d-$this->duration l-{$j['level']} i-{$j['intensity']}";

        $this->search_string = "$baseSearch $prefixedSearch";
        $this->search_array = $this->buildSearchArray($this->search_string);
    }

    /**
     * @param mixed $mustache
     */
    public function getHtml($mustache): string
    {
        if (!isset($this->html)) {
            $this->card_body = $mustache->render($this->card_body_template, $this);
            $this->html = $mustache->render('table_card', $this);
        }

        return $this->html;
    }

    public function getCss(): string|false
    {
        if (!isset($this->css)) {
            $this->css = file_get_contents(__DIR__ . '/../../public/assets/stylesheet.css');
        }

        return $this->css;
    }
}
