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
    $route->setOgImage('code');

    $api = new ApiClient('code');

    try {
        // README HTML partial built from GitHub (see `just build-code-github`).
        $objectContents = $api->getHtmlPartial($objectId);
    } catch (Exception $e) {
        // No README partial (e.g. unknown project) — fall back to the project's
        // description so the page still renders rather than 500-ing.
        $objects = $api->getRaw();
        $description = $objects[$objectId]['description'] ?? '';
        $objectContents = '<article class="markdown-body"><p>'
            . htmlspecialchars($description) . '</p></article>';
    }

    $route->renderObject('object', ['object' => $objectContents]);
}
