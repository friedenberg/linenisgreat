<?php

declare(strict_types=1);

// Feed front controller. /<type>/feed.atom and /<type>/feed.rss render the
// API collection <type> as Atom/RSS; an optional ?q= filters with the same
// grammar as the frontend search box (inherited from the collection page).

$type = $_GET['type'] ?? '';
$format = $_GET['format'] ?? 'atom';
$queryString = $_GET['q'] ?? '';

if ($type === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'feed type required';
    return;
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'atom.linenisgreat.com';
$selfUrl = "{$scheme}://{$host}" . ($_SERVER['REQUEST_URI'] ?? "/{$type}/feed.{$format}");

$items = (new FeedClient())->getCollection($type);
$xml = (new FeedBuilder())->build(
    $type,
    $items,
    $format === 'rss' ? 'rss' : 'atom',
    $selfUrl,
    new FeedQuery($queryString),
);

$contentType = $format === 'rss'
    ? 'application/rss+xml; charset=utf-8'
    : 'application/atom+xml; charset=utf-8';

header("Content-Type: {$contentType}");
header('Access-Control-Allow-Origin: *');
echo $xml;
