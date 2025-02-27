<?php declare(strict_types=1);

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
  $firstTwo = array_slice($parts, 0, 2);
  $objectId = implode("/", $firstTwo);
}

$route = new RouteObject($tab, $objectId);

$fileContents = file_get_contents( __DIR__ . "/objects/$objectId/index.html");

if (!$fileContents) {
  throw new Exception("object does not exist");
}

$route->renderObject(
  $template,
  [
    'object' => $fileContents,
  ],
);
