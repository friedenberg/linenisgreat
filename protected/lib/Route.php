<?php

declare(strict_types=1);

class Route
{
    public $nav;
    public $mustache;
    public $title;

    /**
     * @param string $title
     */
    public function __construct(
        $title,
    ) {
        $this->nav = new Nav($title);
        $this->title = $title;

        $options = array('extension' => '.html.mustache');

        $this->mustache = new Mustache_Engine(array(
            'loader' => new Mustache_Loader_FilesystemLoader(
                __DIR__ . '/templates',
                $options,
            ),
            'entity_flags' => ENT_QUOTES,
        ));
    }

    /**
     * @return array<string,string>
     */
    public function getSiteData(): array
    {
        return [
            "title" => $this->title,
            "favicon" => "assets/favicon.png",
        ];
    }
    public function getCodeMetaRaw(): string
    {
        if (strcmp($this->title, "code") != 0) {
            return "";
        }

        if (empty($this->path) || strcmp($this->path, "/") == 0) {
            return "";
        }

        $elements = explode("/", $this->path);
        $key = $elements[1];
        $raw = $this->parser->getRaw();
        $zettel = [];

        foreach ($raw as $someZettel) {
            if (strcmp($someZettel['blob']['name'], $key) === 0) {
                $zettel = $someZettel;
                break;
            }
        }

        /* $zettel = $raw[$key] ?? []; */

        if (empty($zettel)) {
            // TODO 404
        }

        $code = $zettel['blob']['name'];
        /* $remainder = implode("/", array_slice($elements, 2)); */
        /* $code['name'] = $code['name'] . '/' . $remainder; */
        /* $code['url'] = $code['url'] . $remainder; */

        return $this->mustache->render($code['template'], $code);
    }

    /**
     * @return array<string,string>
     */
    public function getMeta(): array
    {
        $meta = $this->getSiteData();
        $meta['raw'] = $this->getCodeMetaRaw($this->mustache);

        return $meta;
    }

    public function getCardTemplate(): string
    {
        switch ($this->title) {
            case "code":
                return "card_code_project";

            default:
                return "cocktail_card";
        }
    }

    public function getObjects($objectClassName, $urlPrefix): array
    {
        if (isset($this->objects)) {
            return $this->objects;
        }

        $this->objects = $this->parser->parseCustomClass($objectClassName, $urlPrefix);

        /* foreach ($this->objects as $object) { */
        /*   $path = $object->getLocalPath($this->mustache); */

        /*   if (file_exists($path)) { */
        /*     continue; */
        /*   } */

        /*   $object->writeToPath($path); */
        /* } */

        $this->objects = array_values($this->objects);

        return $this->objects;
    }

    private function makeTemplateArgs(...$extra): array
    {
        return array_merge(
            [
                'nav' => array_values($this->nav->tiles),
                'meta' => $this->getMeta(),
                'stylesheets' => [
                    "stylesheet",
                    "zettels",
                    "fonts",
                ],
            ],
            ...$extra,
        );
    }

    public function render(
        $template,
        $args = [],
    ): void {
        $mustache = $this->mustache;

        echo $this->mustache->render(
            $template,
            $this->makeTemplateArgs(
                $args,
            ),
        );
    }

    public function renderWithExtraStylesheets(
        $template,
        $stylesheets,
        $args,
    ): void {
        $mustache = $this->mustache;

        $args = $this->makeTemplateArgs(
            $args,
        );

        array_push($args["stylesheets"], ...$stylesheets);

        echo $this->mustache->render(
            $template,
            $args,
        );
    }
}
