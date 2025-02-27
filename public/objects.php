<?php declare(strict_types=1);

$tab = $_GET['tab'] ?? 'objects';

$route = new RouteObjectOrObjectsIndex($tab);

$route->renderIndex('common', 'Objekt', "/$tab/");
