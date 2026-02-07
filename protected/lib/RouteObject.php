<?php

declare(strict_types=1);

// TODO inherit from Route
class RouteObject
{
    public $nav;
    public $mustache;
    public $title;

    private $object;
    private $objectId;

    /**
     * @param ZettelParser $parser
     */
    private $parser;

    /**
     * @param string $title
     * @param string $objectId
     */
    public function __construct(
        $title,
        $objectId = null,
    ) {
        $this->nav = new Nav($title);
        $this->title = $title;
        $this->objectId = $objectId;
        $objectsFile = __DIR__ . "/../../public/objects.json";
        $this->parser = new ZettelParser($objectsFile);

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

        if (is_null($this->objectId)) {
            return "";
        }

        $raw = $this->parser->getRaw();
        $object = [];

        foreach ($raw as $someZettel) {
            if (strcmp($someZettel['blob']['name'], $this->objectId) === 0) {
                $object = $someZettel;
                break;
            }
        }

        if (empty($object)) {
            return "";
        }

        $code = $object['blob']['name'];

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
    /**
     * @param mixed $objectClassName
     * @param mixed $urlPrefix
     */
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

    /**
     * @param mixed $extra
     */
    public function makeTemplateArgsMetadata(
        $objectClassName,
        $urlPrefix,
    ): array {
        $objects = $this->parser->parseCustomClass($objectClassName, $urlPrefix);
        $object = $objects[$this->objectId];

        return [
            'metadata' => [
                'objectId' => $this->objectId,
                'description' => $object->description,
                'fields' => [
                    [
                        'key' => 'type',
                        'value' => $object->type,
                    ],
                    [
                        'key' => 'tags',
                        'value' => $object->tags,
                    ],
                    [
                        'key' => 'id',
                        'value' => $this->objectId,
                    ],
                    [
                        'key' => 'updated',
                        'value' => $object->date,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param mixed $extra
     */
    private function makeTemplateArgs(
        ...$extra,
    ): array {
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

    /**
     * @param mixed $template
     * @param mixed $args
     */
    public function renderObject(
        $template,
        ...$args,
    ): void {
        echo $this->mustache->render(
            $template,
            $this->makeTemplateArgs(...$args),
        );
    }
}
