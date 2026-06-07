<?php

declare(strict_types=1);

class ApiRouter
{
    private DataSource $dataSource;
    private ApiResponse $response;

    /** @var array<array{method: string, pattern: string, handler: callable}> */
    private array $routes = [];

    public function __construct(DataSource $dataSource, ApiResponse $response)
    {
        $this->dataSource = $dataSource;
        $this->response = $response;
        $this->registerRoutes();
    }

    public function dispatch(string $method, string $uri): void
    {
        if ($method === 'OPTIONS') {
            $this->response->sendOptionsResponse();
            return;
        }

        $path = ltrim(parse_url($uri, PHP_URL_PATH), '/');

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $regex = '~^' . $route['pattern'] . '$~';

            if (preg_match($regex, $path, $matches)) {
                array_shift($matches);
                ($route['handler'])(...$matches);
                return;
            }
        }

        $this->response->sendNotFound("No route matches: /{$path}");
    }

    private function get(string $pattern, callable $handler): void
    {
        $this->routes[] = [
            'method' => 'GET',
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    private function registerRoutes(): void
    {
        $ds = $this->dataSource;
        $res = $this->response;

        // Collection endpoints (notes is an alias for objects)
        foreach (['objects', 'notes', 'yoga', 'yoga-objects', 'code', 'cocktails', 'slides', 'events'] as $type) {
            $this->get($type, function () use ($ds, $res, $type) {
                $data = $ds->getCollection($type);
                $res->sendJson($data, $type);
            });
        }

        // HTML partials (objects + code project READMEs)
        foreach (['objects', 'code'] as $type) {
            $this->get("{$type}/(.+)/html", function (string $id) use ($ds, $res, $type) {
                $html = $ds->getHtmlPartial($type, $id);

                if ($html === null) {
                    $res->sendNotFound("{$type} HTML not found: {$id}");
                    return;
                }

                $res->sendHtml($html);
            });
        }

        // Dodder-style blob/formats endpoints. Registered BEFORE the generic
        // item route so the longer, more specific patterns win even if the
        // ^...$ dispatch anchoring did not already separate them.

        // List the formats available for one item (mirrors dodder's
        // objects/{id}/blob/formats listing).
        $this->get('([\w-]+)/([^/]+)/blob/formats', function (string $type, string $id) use ($ds, $res) {
            $item = $ds->getItem($type, $id);

            if ($item === null) {
                $res->sendNotFound("{$type} item not found: {$id}");
                return;
            }

            $formats = [
                [
                    'format_id' => 'og-image',
                    'uri' => "{$type}/{$id}/blob/formats/og-image",
                ],
            ];

            // Types that ship a build-time HTML partial expose an `html` format
            // too (same set as the HTML-partial route above).
            if (in_array($type, ['objects', 'code'], true)) {
                $formats[] = [
                    'format_id' => 'html',
                    'uri' => "{$type}/{$id}/html",
                ];
            }

            // Events additionally expose an `ics` format (the VEVENT this
            // `!event` is a flat representation of).
            if ($type === 'events') {
                $formats[] = [
                    'format_id' => 'ics',
                    'uri' => "{$type}/{$id}/blob/formats/ics",
                ];
            }

            $res->sendJson($formats, $type);
        });

        // Serve an event's iCalendar (VEVENT) as a format. The `ics` footer link
        // hits this over https (downloads via Content-Disposition); the "add to
        // cal" link hits the same path over webcal:// so the OS opens the
        // default calendar app.
        $this->get('events/([^/]+)/blob/formats/ics', function (string $id) use ($ds, $res) {
            $item = $ds->getItem('events', $id);

            if ($item === null) {
                $res->sendNotFound("events item not found: {$id}");
                return;
            }

            $ics = (new IcsBuilder())->build($item, $id);
            $res->sendCalendar($ics, "{$id}.ics");
        });

        // Serve the OG image as a format: build the card, rasterize+cache via
        // the shared Card\ package, then 302 to the cached hcti.io URL.
        $this->get('([\w-]+)/([^/]+)/blob/formats/og-image', function (string $type, string $id) use ($ds, $res) {
            $item = $ds->getItem($type, $id);

            if ($item === null) {
                $res->sendNotFound("{$type} item not found: {$id}");
                return;
            }

            // The hcti.io key is materialized from the piggy store into a
            // gitignored Html2ImageApiKey class at deploy (a later secrets
            // task). Without it the format is genuinely unavailable, not
            // missing — answer 503, not 404, so callers/caches don't treat it
            // as a permanent absence.
            if (!class_exists('Html2ImageApiKey')) {
                http_response_code(503);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'og-image unavailable: missing Html2ImageApiKey']);
                return;
            }

            try {
                $renderer = \Card\CardRenderer::withPackageTemplates();
                $og = new \Card\OgImage($renderer, Html2ImageApiKey::KEY, __DIR__ . '/../../tmp');
                $res->sendRedirect($og->urlFor($type, $item));
            } catch (\Throwable $e) {
                // Don't 500-leak the renderer/hcti failure (display_errors is
                // Off in prod, but be defensive); report a clean 502.
                http_response_code(502);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'og-image render failed']);
            }
        });

        // Item endpoints
        foreach (['objects', 'yoga-objects', 'code', 'events'] as $type) {
            $this->get("{$type}/([^/]+)", function (string $id) use ($ds, $res, $type) {
                $item = $ds->getItem($type, $id);

                if ($item === null) {
                    $res->sendNotFound("{$type} item not found: {$id}");
                    return;
                }

                $res->sendJson($item, $type);
            });
        }
    }
}
