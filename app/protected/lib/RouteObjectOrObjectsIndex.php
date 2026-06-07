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

    /** API collection name for the og:image format URL, set only on detail pages. */
    private ?string $ogImageType = null;

    /** Collection type whose Atom/RSS feeds this index links, or null. */
    private ?string $feedType = null;

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
     * single code-project page; "" everywhere else. Built on the framework
     * object_footer (ObjectFooter) so code and events share one footer concept.
     */
    public function getCodeFooter(): string
    {
        $code = $this->findCodeMeta();

        if (empty($code)) {
            return "";
        }

        $links = [];
        if (!empty($code['url'])) {
            $links[] = ['label' => 'github', 'href' => $code['url']];
        }
        if (!empty($code['license_url'])) {
            $links[] = ['label' => 'license', 'href' => $code['license_url']];
        }

        return $this->mustache->render(
            'object_footer',
            ObjectFooter::build($code['readme_updated'] ?? null, $links),
        );
    }

    /**
     * Advertise this index's Atom/RSS feeds (framework-level): adds the
     * alternate <link>s in <head> and the visible feed links at the bottom of
     * the collection page, both pointing at the atom.* host. $type is the API
     * collection name the feed host serves (e.g. 'events').
     *
     * @param string $type
     */
    public function setFeed(string $type): void
    {
        $this->feedType = $type;
    }

    /**
     * Feed URLs for the configured type, or null when no feed is set. The host
     * is the standalone feed app (ATOM_BASE_URL, default
     * https://atom.linenisgreat.com).
     *
     * @return array<string,string>|null
     */
    private function getFeed(): ?array
    {
        if (is_null($this->feedType)) {
            return null;
        }

        $base = rtrim(
            getenv('ATOM_BASE_URL') ?: 'https://atom.linenisgreat.com',
            '/',
        );

        return [
            'type' => $this->feedType,
            'atom' => "{$base}/{$this->feedType}/feed.atom",
            'rss' => "{$base}/{$this->feedType}/feed.rss",
        ];
    }

    /**
     * Activate the absolute og:image meta for this single-card detail page,
     * pointing at the API's request-time format endpoint. $apiType is the API
     * collection name (e.g. 'code'); the host matches the API the app talks to
     * (API_BASE_URL).
     *
     * @param string $apiType
     */
    public function setOgImage(string $apiType): void
    {
        $this->ogImageType = $apiType;
    }

    /**
     * Build the absolute og:image URL from the configured API base, or null
     * when this isn't an og:image-bearing detail page.
     */
    private function getOgImageUrl(): ?string
    {
        if (is_null($this->ogImageType) || is_null($this->objectId)) {
            return null;
        }

        $base = rtrim(
            getenv('API_BASE_URL') ?: 'https://api.linenisgreat.com',
            '/',
        );

        return "{$base}/{$this->ogImageType}/{$this->objectId}/blob/formats/og-image";
    }

    /**
     * @return array<string,string>
     */
    public function getMeta(): array
    {
        $meta = $this->getSiteData();
        $meta['raw'] = $this->getCodeMetaRaw();
        $meta['footer'] = $this->getCodeFooter();

        $ogImageUrl = $this->getOgImageUrl();
        if (!is_null($ogImageUrl)) {
            $meta['image'] = true;
            $meta['og_image_url'] = $ogImageUrl;
        }

        $feed = $this->getFeed();
        if (!is_null($feed)) {
            $meta['feed_atom'] = $feed['atom'];
            $meta['feed_rss'] = $feed['rss'];
        }

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
                'feed' => $this->getFeed(),
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
