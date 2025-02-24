<?php declare(strict_types=1);

$route = new RouteObject('about');
$path = __DIR__ . "/about.html";
$fileContents = file_get_contents($path);

if (!$fileContents) {
  throw new Exception("object not found: $path");
}

$route->renderObject(
  'object',
  [
    'object' => $fileContents,
  ],
);
