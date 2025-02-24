<?php declare(strict_types=1);

$args = $_GET['args'] ?? null;

if (!is_null($args)) {
  $url = parse_url($args);
}

$path = $url['path'] ?? null;

$objectId = null;

if (!is_null($path)) {
  $objectId = explode("/", $path)[0];
}

$route = new RouteObjectOrObjectsIndex('code', $objectId);
$route->renderIndex('common', 'CodeProject', '/code/');
