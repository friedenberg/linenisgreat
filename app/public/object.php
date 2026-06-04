<?php

declare(strict_types=1);

$tab = $_GET['tab'] ?? 'notes';
$args = $_GET['args'] ?? null;
$template = $_GET['template'] ?? 'object';

if (!is_null($args)) {
    $url = parse_url($args);
}

$path = $url['path'] ?? null;

$objectId = null;

if (!is_null($path)) {
    $parts = explode('/', $path);
    $parts = array_slice($parts, 0, 2);
    $objectId = implode("/", $parts);
}

$route = new RouteObject($tab, $objectId);

if (!is_null($objectId)) {
    $route->setOgImage('objects');
}

$api = new ApiClient('objects');

try {
    $objectContents = $api->getHtmlPartial($objectId);
} catch (Exception $e) {
    // No HTML partial (e.g. objects data not built locally, or unknown id) —
    // degrade to a placeholder instead of fataling. Mirrors code.php.
    $objectContents = '<article class="markdown-body"><p>Object not found.</p></article>';
}

if (!$objectContents) {
    $objectContents = '<article class="markdown-body"><p>Object not found.</p></article>';
}

$route->renderObject(
    $template,
    [
    'object' => $objectContents,
    ],
);
