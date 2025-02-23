<?php declare(strict_types=1);

$route = new RouteObjectOrObjectsIndex('slides');

$objectId = $_GET['objectId'] ?? null;

if (is_null($objectId)) {
  $route->renderIndex('common', 'Zettel2', '/slides/');
} else {
  $fileContents = file_get_contents( __DIR__ . "/$objectId/index.html");

  if (!$fileContents) {
    throw new Exception("slide does not exist");
  }

  $route->renderObject(
    'slide',
    [
      'slide' => $fileContents,
    ],
  );
}
