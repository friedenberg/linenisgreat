<?php declare(strict_types=1);

$route = new RouteObject('meet');
$fileContents = '<iframe src="https://app.simplymeet.me/sasha-f?is_widget=1&view=compact" style="width: 100%; height: 100%;" frameborder="0" scrolling="yes"></iframe>';

$route->renderObject(
  'meet',
  [
    'object' => $fileContents,
  ],
);
