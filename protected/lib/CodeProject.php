<?php

declare(strict_types=1);

class CodeProject
{
    use FieldMappingTrait;
    public $type;
    public $meta;
    public $card_body_template;
    public $icon_css_class;
    public $name;
    public $title;
    public $search_string;
    public $search_array;
    public $html;
    public $css;
    public $card_body;
    public $description;
    public $url;

    /**
     * @param array $j
     */
    public function __construct($j)
    {
        $this->type = $j['type'] ?? "";
        $this->description = $j['description'] ?? "";
        $this->name = $j['description'];
        $this->title = $j['object-id'];

        $data = $j['blob'];

        if (is_string($data)) {
            $this->description = $data;
        }

        if (!empty($data['name'])) {
            $this->title = $data['name'];
        }

        $this->meta = $data['meta'] ?? [];

        $this->search_string = "$this->name $this->title";
        $this->search_array = $this->buildSearchArray($this->search_string);
        $this->card_body_template = "card_common";
        $this->url = $this->buildUrl("/code/", $this->title);
    }

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
