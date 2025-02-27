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

$route = new RouteObjectOrObjectsIndex('notes', $objectId);

if (is_null($objectId)) {
  $route->renderIndex('common', 'Objekt', '/notes/');
} else {
  $fileContents = file_get_contents( __DIR__ . "/../$objectId/index.html");

  if (!$fileContents) {
    throw new Exception("object does not exist");
  }

  $route->renderObject(
    'object',
    [
      'object' => $fileContents,
    ],
  );
}
