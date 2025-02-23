<?php declare(strict_types=1);

$route = new RouteObjectOrObjectsIndex('notes');

$objectId = $_GET['objectId'] ?? null;

if (is_null($objectId)) {
  $route->renderIndex('common', 'Zettel2', '/notes/');
} else {
  $fileContents = file_get_contents( __DIR__ . "/$objectId/index.html");

  if (!$fileContents) {
    throw new Exception("note does not exist");
  }

  $route->renderObject(
    'note',
    [
      'object' => $fileContents,
    ],
  );
}
