<?php

declare(strict_types=1);

class Zettel2
{
    use FieldMappingTrait;
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
    public function __construct($j, $urlPrefix = "/")
    {
        $this->title = $j['title'];
        $this->subtitle = $j['subtitle'];
        $this->description = $j['description'];
        $this->tags = $this->normalizeTags($j['tags'] ?? []);
        $this->objectId = $this->extractObjectId($j);
        $this->url = $this->buildUrl($urlPrefix, $this->objectId, $this->title);

        $this->search_string = "$this->title $this->subtitle $this->description $this->tags";
        $this->search_array = $this->buildSearchArray($this->search_string);

        $this->card_body_template = "card_common";
    }

}
