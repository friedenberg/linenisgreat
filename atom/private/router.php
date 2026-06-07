<?php

declare(strict_types=1);

/**
 * Unified router for the feed app (atom.linenisgreat.com) — serves as both:
 * 1. Router script for PHP built-in server: php -S localhost:2224 router.php
 * 2. Generator for .htaccess: php router.php --generate-htaccess > public/.htaccess
 *
 * Mirrors app/private/router.php (single route array drives both), trimmed to
 * the feed routes: <type>/feed.atom and <type>/feed.rss.
 */

$routes = [
    [
        'pattern' => '^([\w-]+)/feed\.atom$',
        'file' => 'feed.php',
        'params' => ['type' => '$1', 'format' => 'atom'],
    ],
    [
        'pattern' => '^([\w-]+)/feed\.rss$',
        'file' => 'feed.php',
        'params' => ['type' => '$1', 'format' => 'rss'],
    ],
];

/**
 * Generate .htaccess content from route definitions.
 */
function atomGenerateHtaccess(array $routes): string
{
    $lines = [
        'Options +FollowSymLinks -Indexes',
        'RewriteEngine On',
        '',
    ];

    foreach ($routes as $route) {
        $queryParts = [];
        foreach ($route['params'] as $key => $value) {
            $queryParts[] = "{$key}={$value}";
        }
        $target = $route['file'] . '?' . implode('&', $queryParts);

        $lines[] = "RewriteRule \"{$route['pattern']}\" \"{$target}\" [L,PT,B,QSA]";
    }

    $lines[] = '';
    return implode("\n", $lines);
}

/**
 * Route a request using PHP (for built-in server).
 * Returns false if the request should be handled as a static file.
 */
function atomRouteRequest(array $routes, string $uri): bool
{
    $path = ltrim(parse_url($uri, PHP_URL_PATH), '/');

    if ($path !== '' && file_exists(__DIR__ . '/../public/' . $path)) {
        return false;
    }

    foreach ($routes as $route) {
        $regex = '~' . $route['pattern'] . '~';

        if (preg_match($regex, $path, $matches)) {
            foreach ($route['params'] as $key => $value) {
                $value = preg_replace_callback('/\$(\d+)/', function ($m) use ($matches) {
                    return $matches[(int) $m[1]] ?? '';
                }, $value);
                $_GET[$key] = $value;
            }

            $targetFile = __DIR__ . '/../public/' . $route['file'];
            if (file_exists($targetFile)) {
                include $targetFile;
                return true;
            }
        }
    }

    http_response_code(404);
    echo "404 Not Found: {$path}";
    return true;
}

// CLI mode - generate .htaccess
if (php_sapi_name() === 'cli') {
    if (in_array('--generate-htaccess', $argv ?? [], true)) {
        echo atomGenerateHtaccess($routes);
        exit(0);
    }

    echo "Usage:\n";
    echo "  php router.php --generate-htaccess    Generate .htaccess content\n";
    echo "  php -S localhost:2224 router.php      Run as development server router\n";
    exit(1);
}

// Built-in server mode - route the request
return atomRouteRequest($routes, $_SERVER['REQUEST_URI']);
