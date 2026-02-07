<?php

declare(strict_types=1);

class Objekt
{
    use FieldMappingTrait;
    public $type;
    public $tags;
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
    public function __construct($j, $urlPrefix = "")
    {
        $this->type = $j['type'];
        $this->description = $this->extractWithFallback($j, ['description', 'duration'], "");
        $this->date = $j['date'] ?? "";
        $this->objectId = $this->extractObjectId($j);
        $this->tags = $this->normalizeTags($j['tags'] ?? []);

        $this->search_string = "$this->name $this->objectId $this->description $this->type $this->tags";
        $this->search_array = $this->buildSearchArray($this->search_string);
        $this->card_body_template = "card_object";
        $this->url = $this->buildUrl($urlPrefix, $this->objectId, $this->description);
    }

}
