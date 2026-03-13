<?php

declare(strict_types=1);

/**
 * Unified router - serves as both:
 * 1. Router script for PHP built-in server: php -S localhost:2222 router.php
 * 2. Generator for .htaccess: php router.php --generate-htaccess > public/.htaccess
 */

$routes = [
    // Homepage
    [
        'pattern' => '^$',
        'file' => 'object.php',
        'params' => ['tab' => 'about', 'args' => 'digastric/kitts'],
    ],

    // Simple section index pages
    [
        'pattern' => '^(yoga|code|objects|notes|slides|cocktails|resume|meet)/?$',
        'file' => '$1.php',
    ],

    // Objects with metadata
    [
        'pattern' => '^(objects)/(.+)$',
        'file' => 'object_with_metadata.php',
        'params' => ['tab' => '$1', 'args' => '$2'],
    ],

    // Notes with metadata
    [
        'pattern' => '^(notes)/(.+)$',
        'file' => 'object_with_metadata.php',
        'params' => ['tab' => '$1', 'args' => '$2'],
    ],

    // Slides
    [
        'pattern' => '^(slides)/(.+)$',
        'file' => 'object.php',
        'params' => ['tab' => '$1', 'template' => 'slide', 'args' => '$2'],
    ],

    // Individual yoga object
    [
        'pattern' => '^yoga/(.+)$',
        'file' => 'yoga_object.php',
        'params' => ['args' => '$1'],
    ],

    // Code project with optional remainder
    [
        'pattern' => '^code/(\w+)(.+)?$',
        'file' => 'code.php',
        'params' => ['project' => '$1', 'remainder' => '$2'],
    ],
];

$redirects = [
    // Subdomain redirect
    [
        'condition' => '%{HTTP_HOST} ^code.linenisgreat.com$',
        'pattern' => '^/?(.*)$',
        'target' => 'https://linenisgreat.com/code/$1',
        'flags' => 'R=302,L',
    ],
];

/**
 * Generate .htaccess content from route definitions
 */
function generateHtaccess(array $routes, array $redirects): string
{
    $lines = [
        'Options +FollowSymLinks -Indexes',
        'RewriteEngine On',
        '',
    ];

    foreach ($routes as $route) {
        $pattern = $route['pattern'];
        $file = $route['file'];

        if (isset($route['params'])) {
            $queryParts = [];
            foreach ($route['params'] as $key => $value) {
                $queryParts[] = "{$key}={$value}";
            }
            $target = $file . '?' . implode('&', $queryParts);
            $flags = '[L,PT,B]';
        } else {
            $target = $file;
            $flags = '';
        }

        $line = "RewriteRule \"{$pattern}\" \"{$target}\"";
        if ($flags) {
            $line .= " {$flags}";
        }
        $lines[] = $line;
    }

    if (!empty($redirects)) {
        $lines[] = '';
        foreach ($redirects as $redirect) {
            if (isset($redirect['condition'])) {
                $lines[] = "RewriteCond {$redirect['condition']}";
            }
            $lines[] = "RewriteRule {$redirect['pattern']} {$redirect['target']} [{$redirect['flags']}]";
        }
    }

    $lines[] = '';
    return implode("\n", $lines);
}

/**
 * Route a request using PHP (for built-in server)
 * Returns false if the request should be handled as a static file
 */
function routeRequest(array $routes, string $uri): bool
{
    // Remove leading slash and query string
    $path = ltrim(parse_url($uri, PHP_URL_PATH), '/');

    // Let static files pass through
    if ($path !== '' && file_exists(__DIR__ . '/../public/' . $path)) {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $staticExtensions = ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'json', 'html'];
        if (in_array(strtolower($ext), $staticExtensions, true)) {
            return false;
        }
    }

    foreach ($routes as $route) {
        // Convert htaccess pattern to PHP regex
        $regex = '~' . $route['pattern'] . '~';

        if (preg_match($regex, $path, $matches)) {
            $file = $route['file'];

            // Replace $1, $2, etc. in file name
            $file = preg_replace_callback('/\$(\d+)/', function ($m) use ($matches) {
                return $matches[(int)$m[1]] ?? '';
            }, $file);

            // Set up $_GET parameters
            if (isset($route['params'])) {
                foreach ($route['params'] as $key => $value) {
                    // Replace $1, $2, etc. in param values
                    $value = preg_replace_callback('/\$(\d+)/', function ($m) use ($matches) {
                        return $matches[(int)$m[1]] ?? '';
                    }, $value);
                    $_GET[$key] = $value;
                }
            }

            // Include the target PHP file
            $targetFile = __DIR__ . '/../public/' . $file;
            if (file_exists($targetFile)) {
                include $targetFile;
                return true;
            }
        }
    }

    // No route matched - try serving from public directory
    if ($path !== '' && file_exists(__DIR__ . '/../public/' . $path)) {
        return false;
    }

    // 404
    http_response_code(404);
    echo "404 Not Found: {$path}";
    return true;
}

// CLI mode - generate .htaccess
if (php_sapi_name() === 'cli') {
    if (in_array('--generate-htaccess', $argv ?? [], true)) {
        echo generateHtaccess($routes, $redirects);
        exit(0);
    }

    echo "Usage:\n";
    echo "  php router.php --generate-htaccess    Generate .htaccess content\n";
    echo "  php -S localhost:2222 router.php      Run as development server router\n";
    exit(1);
}

// Built-in server mode - route the request
return routeRequest($routes, $_SERVER['REQUEST_URI']);
