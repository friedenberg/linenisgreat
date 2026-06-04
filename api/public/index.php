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

// When DODDER_API_URL is set, serve objects and blobs live from a
// `dodder serve -public` HTTP backend (dodder + madder) instead of the
// pre-exported flat files. Code-project READMEs still read through to
// GitHub via the same decorator. Unset, the site stays on the
// build-time FileDataSource — so the switch is opt-in per deploy.
$dodderApiUrl = getenv('DODDER_API_URL');

$baseDataSource = ($dodderApiUrl !== false && $dodderApiUrl !== '')
    ? new DodderHttpDataSource($dodderApiUrl)
    : new FileDataSource($dataDir);

$dataSource = new CodeReadmeDataSource($baseDataSource, $readmeClient);
$response = new ApiResponse($allowedOrigin);
$router = new ApiRouter($dataSource, $response);

$router->dispatch(
    $_SERVER['REQUEST_METHOD'],
    $_SERVER['REQUEST_URI'],
);
