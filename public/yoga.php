<?php declare(strict_types=1);

$args = $_GET['args'] ?? null;

if (!is_null($args)) {
  $url = parse_url($args);
}

$path = $url['path'] ?? null;

$objectId = null;

if (!is_null($path)) {
  $objectId = $path;
}

$route = new RouteObjectOrObjectsIndex('yoga', $objectId);
$route->renderIndex('common', 'Yoga', '/yoga/');
