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

$api = new ApiClient('objects');
$objectContents = $api->getHtmlPartial($objectId);

if (!$objectContents) {
    throw new Exception("object does not exist");
}

$route->renderObject(
    $template,
    [
    'object' => $objectContents,
    ],
);
