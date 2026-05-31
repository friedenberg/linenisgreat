<?php

declare(strict_types=1);

// TODO inherit from Route
class RouteObjectOrObjectsIndex
{
    public $nav;
    public $mustache;
    public $title;

    private $objects;
    private $objectId;

    /**
     * @param ApiClient $parser
     */
    private $parser;

    /**
     * @param string $title
     * @param mixed  $objectId
     */
    public function __construct(
        $title,
        $objectId = null,
    ) {
        $this->nav = new Nav($title);
        $this->title = $title;
        $this->objectId = $objectId;
        $this->parser = new ApiClient($title);

        $options = array('extension' => '.html.mustache');

        $this->mustache = new Mustache\Engine(
            array(
                'loader' => new Mustache\Loader\FilesystemLoader(
                    __DIR__ . '/templates',
                    $options,
                ),
                'entity_flags' => ENT_QUOTES,
            )
        );
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

    /**
     * Find the code.json meta for the current single code-project page, or []
     * when this isn't a code page, has no object id, or no entry matches.
     *
     * @return array<string,mixed>
     */
    private function findCodeMeta(): array
    {
        if (strcmp($this->title, "code") != 0 || is_null($this->objectId)) {
            return [];
        }

        foreach ($this->parser->getRaw() as $someZettel) {
            if (strcmp($someZettel['blob']['name'], $this->objectId) === 0) {
                return $someZettel['blob']['meta'] ?? [];
            }
        }

        return [];
    }

    public function getCodeMetaRaw(): string
    {
        $code = $this->findCodeMeta();

        // Only Go projects carry a head-meta template (code_go_import); others
        // (and non-code pages) have none, so render nothing.
        if (empty($code) || !isset($code['template'])) {
            return "";
        }

        return $this->mustache->render($code['template'], $code);
    }

    /**
     * Render the per-repo footer (github/license links + last-updated) for a
     * single code-project page; "" everywhere else.
     */
    public function getCodeFooter(): string
    {
        $code = $this->findCodeMeta();

        if (empty($code)) {
            return "";
        }

        return $this->mustache->render('code_footer', $code);
    }

    /**
     * @return array<string,string>
     */
    public function getMeta(): array
    {
        $meta = $this->getSiteData();
        $meta['raw'] = $this->getCodeMetaRaw();
        $meta['footer'] = $this->getCodeFooter();

        return $meta;
    }
    /**
     * @param  mixed $objectClassName
     * @param  mixed $urlPrefix
     * @return <missing>|array
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
     * @param  mixed $extra
     * @return array<string,mixed>
     */
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

    /**
     * @param mixed $template
     * @param mixed $objectClassName
     * @param mixed $urlPrefix
     * @param mixed $args
     */
    public function renderIndex(
        $template,
        $objectClassName,
        $urlPrefix,
        $args = [],
    ): void {
        // TODO render certain objects as hidden based on their tags
        $mustache = $this->mustache;

        echo $this->mustache->render(
            $template,
            $this->makeTemplateArgs(
                [
                    'objects' => array_map(
                        function ($object) use ($mustache) {
                            return $object->getHtml($mustache);
                        },
                        $this->getObjects($objectClassName, $urlPrefix),
                    ),
                ],
                $args,
            ),
        );
    }

    /**
     * @param mixed $template
     * @param mixed $args
     */
    public function renderObject($template, $args): void
    {
        echo $this->mustache->render(
            $template,
            $this->makeTemplateArgs($args),
        );
    }
}
