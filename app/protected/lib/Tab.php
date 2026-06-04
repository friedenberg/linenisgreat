<?php

declare(strict_types=1);

class Tab
{
    public $mustache;
    public $title;

    private $zettels;
    private $parser;
    private $site;
    /**
     * @param string $title
     * @param string|null $endpoint
     */
    public function __construct(
        $title,
        $endpoint = null,
    ) {
        $this->title = $title;

        if ($endpoint !== null) {
            $this->parser = new ApiClient($endpoint);
        }

        $options = array('extension' => '.html.mustache');

        $this->mustache = new Mustache\Engine(array(
            'loader' => new Mustache\Loader\FilesystemLoader(
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
    /**
     * @param mixed $mustache
     */
    public function getCodeMetaRaw($mustache): string
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

        return $mustache->render($code['template'], $code);
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
     * Parse the endpoint's zettels and pre-render each card's HTML. Card images
     * are produced API-side (Card\OgImage at <type>/<id>/blob/formats/og-image),
     * so there is no longer any tmp-file serialization here — getHtml() hydrates
     * $z->html, which cocktails.php reads directly.
     *
     * @param mixed $mustache
     * @return array<int, Zettel>
     */
    public function getZettels($mustache): array
    {
        if (isset($this->zettels)) {
            return $this->zettels;
        }

        $this->zettels = $this->parser->parse();

        foreach ($this->zettels as $zettel) {
            $zettel->getHtml($mustache);
        }

        $this->zettels = array_values($this->zettels);

        return $this->zettels;
    }

    public function render($template, $args): void
    {
        $this->mustache->render($template, $args);
    }
}
