<?php

declare(strict_types=1);

$dataDir = __DIR__ . '/../protected/data';
$allowedOrigin = getenv('CORS_ORIGIN') ?: 'https://linenisgreat.com';

// Serve /code/<name> READMEs live from GitHub (TTL-cached), falling back to the
// build-time partial / description when read-through is unavailable. The token
// is materialized from the piggy store by `just reveal-secrets`; its absence
// (CI, local) cleanly disables the live path, keeping those environments
// hermetic. See docs/decisions/0003-request-time-readme-read-through.md.
$githubOrg = getenv('CODE_GITHUB_ORG') ?: 'amarbel-llc';
$readmeTtl = getenv('CODE_README_TTL');
$readmeTtl = ($readmeTtl === false || $readmeTtl === '') ? 21600 : (int) $readmeTtl;
$githubToken = class_exists('GithubToken') ? GithubToken::TOKEN : null;

$readmeClient = new GithubReadmeClient(
    $githubOrg,
    $readmeTtl,
    __DIR__ . '/../tmp',
    $githubToken,
    new ReadmeLinkAbsolutizer(),
);

// /blobs/<digest> reverse-proxies to a `madder serve` HTTP backend. Unset
// (CI, prod-without-madder) cleanly disables the route via a guarded 503; the
// local-deploy recipe points it at a localhost madder serve.
$madderBaseUrl = getenv('MADDER_BASE_URL') ?: null;

$dataSource = new CodeReadmeDataSource(new FileDataSource($dataDir), $readmeClient);
$response = new ApiResponse($allowedOrigin);
$router = new ApiRouter($dataSource, $response, new MadderClient($madderBaseUrl));

$router->dispatch(
    $_SERVER['REQUEST_METHOD'],
    $_SERVER['REQUEST_URI'],
);
