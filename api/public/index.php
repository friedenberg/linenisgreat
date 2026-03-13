<?php

declare(strict_types=1);

$dataDir = __DIR__ . '/../protected/data';
$allowedOrigin = getenv('CORS_ORIGIN') ?: 'https://linenisgreat.com';

$dataSource = new FileDataSource($dataDir);
$response = new ApiResponse($allowedOrigin);
$router = new ApiRouter($dataSource, $response);

$router->dispatch(
    $_SERVER['REQUEST_METHOD'],
    $_SERVER['REQUEST_URI'],
);
