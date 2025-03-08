<?php declare(strict_types=1);

$tab = "yoga";
$args = $_GET['args'] ?? null;

if (!is_null($args)) {
  $url = parse_url($args);
}

$path = $url['path'] ?? null;

$objectId = null;

if (!is_null($path)) {
  $parts = explode('/', $path);
  $parts = array_slice($parts, 0, 1);

  $objectId = implode("/", $parts);
}

$route = new RouteObject($tab, $objectId);

header("Referrer-Policy: no-referrer");

$objectsFile = __DIR__ . "/yoga_objects.json";
$parser = new ZettelParser($objectsFile);
$objects = $parser->getRaw();
$objectContents = $route->mustache->render('yoga_partial', $objects[$objectId]); 

$route->renderObject(
  'object',
  [
    'object' => $objectContents,
  ],
);
