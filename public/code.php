<?php

declare(strict_types=1);

$args = $_GET['project'] ?? null;
$remainder = $_GET['remainder'] ?? null;

$objectId = null;

if (!is_null($args)) {
    $objectId = $args;
}

$route = new RouteObjectOrObjectsIndex('code', $objectId);

if (is_null($objectId)) {
    $route->renderIndex('common', 'CodeProject', '/code/');
} else {
    $parser = new ApiClient('code');
    $objects = $parser->getRaw();
    $objectContents = $route->mustache->render('object', $objects[$objectId]);
    $route->renderObject('object', ['object' => $objectContents]);
}
