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
        foreach (['objects', 'notes', 'yoga', 'yoga-objects', 'code', 'cocktails', 'slides'] as $type) {
            $this->get($type, function () use ($ds, $res, $type) {
                $data = $ds->getCollection($type);
                $res->sendJson($data, $type);
            });
        }

        // Object HTML partial
        $this->get('objects/(.+)/html', function (string $id) use ($ds, $res) {
            $html = $ds->getHtmlPartial('objects', $id);

            if ($html === null) {
                $res->sendNotFound("Object HTML not found: {$id}");
                return;
            }

            $res->sendHtml($html);
        });

        // Item endpoints
        foreach (['objects', 'yoga-objects', 'code'] as $type) {
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
