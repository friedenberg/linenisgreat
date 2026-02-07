<?php

declare(strict_types=1);

$tab = $_GET['tab'] ?? 'notes';
$args = $_GET['args'] ?? null;
$template = $_GET['template'] ?? 'object';
$parse_class = $_GET['parse_class'] ?? 'Objekt';
$url_prefix = $_GET['url_prefix'] ?? '/notes/';

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

$objectContents = file_get_contents(__DIR__ . "/objects/$objectId/index.html");

if (!$objectContents) {
    throw new Exception("object does not exist");
}

$route->renderObject(
    $template,
    $route->makeTemplateArgsMetadata($parse_class, $url_prefix),
    [
    'object' => $objectContents,
    ],
);
