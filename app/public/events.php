<?php

declare(strict_types=1);

// /events            → card index (with Atom/RSS feed links)
// /events/<id>[/...] → full object view of one event (ics | add to cal footer)

$args = $_GET['args'] ?? null;

$objectId = null;

if (!is_null($args)) {
    $url = parse_url($args);
    $path = $url['path'] ?? null;

    if (!is_null($path)) {
        // The URL is /events/<id>/<slug>; the id is the first path segment.
        $parts = explode('/', $path);
        $objectId = $parts[0];
    }
}

if (is_null($objectId) || $objectId === '') {
    $route = new RouteObjectOrObjectsIndex('events', null);
    $route->setFeed('events');
    $route->renderIndex('common', 'Event', '/events/');
    return;
}

// Detail / full object view.
$route = new RouteObject('events', $objectId, 'events');
$route->setOgImage('events');

$api = new ApiClient('events');
$events = $api->parseCustomClass('Event', '/events/');
$event = $events[$objectId] ?? null;

if (is_null($event)) {
    http_response_code(404);
    echo "404 Not Found: events/{$objectId}";
    return;
}

$apiBase = rtrim(getenv('API_BASE_URL') ?: 'https://api.linenisgreat.com', '/');
$icsHttps = "{$apiBase}/events/{$objectId}/blob/formats/ics";
// webcal:// hands the same .ics resource to the OS default calendar app.
$icsWebcal = preg_replace('#^https?://#', 'webcal://', $icsHttps);

// Humanize the last-updated timestamp for the footer line above the links.
$updated = null;
if ($event->date !== '') {
    try {
        $updated = (new DateTimeImmutable($event->date))->format('M j, Y');
    } catch (Exception $e) {
        $updated = $event->date;
    }
}

$route->setFooter(ObjectFooter::build($updated, [
    ['label' => 'ics', 'href' => $icsHttps, 'download' => true],
    ['label' => 'add to cal', 'href' => $icsWebcal],
]));

$fields = [];
if ($event->when !== '') {
    $fields[] = ['key' => 'when', 'value' => $event->when];
}
if ($event->location !== '') {
    $fields[] = ['key' => 'where', 'value' => $event->location];
}
if ($event->tags !== '') {
    $fields[] = ['key' => 'tags', 'value' => $event->tags];
}
$fields[] = ['key' => 'id', 'value' => $event->objectId];

$body = '<article class="markdown-body"><p>'
    . nl2br(htmlspecialchars($event->description))
    . '</p></article>';

$route->renderObject(
    'object',
    [
        'metadata' => [
            'description' => $event->summary,
            'fields' => $fields,
        ],
    ],
    ['object' => $body],
);
