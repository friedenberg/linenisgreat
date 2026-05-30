<?php

declare(strict_types=1);

/**
 * Read-only git smart-HTTP reverse proxy.
 *
 * Maps the two smart-HTTP read endpoints
 *   GET  .../info/refs?service=git-upload-pack
 *   POST .../git-upload-pack
 * onto the upstream GitHub repo at <CODE_GIT_UPSTREAM>/<project>.git, streaming
 * GitHub's *smart* protocol through unchanged. Because GitHub already speaks the
 * smart protocol, a transparent byte-forwarding proxy is sufficient — no
 * git-http-backend, CGI, or long-running daemon is required, so this runs inside
 * the standard NFSN plain-PHP setup.
 *
 * Read-only is structural: only the upload-pack (fetch/clone) service is ever
 * routed here. git-receive-pack (push) is never wired, and an info/refs request
 * for any service other than git-upload-pack is rejected.
 *
 * Streaming is deliberate — output buffering is torn down and each chunk is
 * flushed as it arrives so a clone neither balloons PHP's memory nor stalls
 * against NFSN's 3-minute wall-clock request cap.
 *
 * Routed from router.php as code_git_proxy.php?project=<name>&endpoint=<endpoint>
 * where <endpoint> is 'info/refs' or 'git-upload-pack'. The proxy reads the real
 * ?service= query param for ref discovery (named 'endpoint' here precisely so the
 * router params do not clobber it).
 */

$project  = $_GET['project']  ?? '';
$endpoint = $_GET['endpoint'] ?? '';
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Repo slug: letters, digits, '.', '_', '-' only. Blocks path traversal and
// keeps us pinned to a single repo under the upstream org.
if ($project === '' || !preg_match('/^[\w.-]+$/', $project) || str_contains($project, '..')) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo "bad project\n";
    return;
}

$upstreamBase = rtrim(getenv('CODE_GIT_UPSTREAM') ?: 'https://github.com/amarbel-llc', '/');

if ($endpoint === 'info/refs' && $method === 'GET') {
    // Ref discovery — read-only upload-pack service only.
    if (($_GET['service'] ?? '') !== 'git-upload-pack') {
        http_response_code(403);
        header('Content-Type: text/plain');
        echo "only git-upload-pack (read-only) is served\n";
        return;
    }
    $url = "{$upstreamBase}/{$project}.git/info/refs?service=git-upload-pack";
} elseif ($endpoint === 'git-upload-pack' && $method === 'POST') {
    $url = "{$upstreamBase}/{$project}.git/git-upload-pack";
} else {
    // Anything else (notably POST git-receive-pack) is not a read endpoint.
    http_response_code(404);
    header('Content-Type: text/plain');
    echo "not found\n";
    return;
}

// Forward only the headers the smart protocol needs. Git-Protocol carries the
// v2 protocol opt-in; Content-Encoding lets git send a gzipped upload-pack body.
$forwardHeaders = [];
$passthrough = [
    'CONTENT_TYPE'         => 'Content-Type',
    'HTTP_CONTENT_ENCODING' => 'Content-Encoding',
    'HTTP_ACCEPT'          => 'Accept',
    'HTTP_GIT_PROTOCOL'    => 'Git-Protocol',
];
foreach ($passthrough as $srv => $name) {
    if (!empty($_SERVER[$srv])) {
        $forwardHeaders[] = "{$name}: {$_SERVER[$srv]}";
    }
}
$forwardHeaders[] = 'User-Agent: ' . ($_SERVER['HTTP_USER_AGENT'] ?? 'git-linenisgreat-proxy');

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => $forwardHeaders,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HEADER         => false,
    // Leave the upstream body byte-identical: do NOT auto-decompress, so a
    // gzipped pack streams through with its Content-Encoding intact.
    CURLOPT_ENCODING       => '',
    CURLOPT_CONNECTTIMEOUT => 10,
    // Stay just under NFSN's 180s wall-clock cap so we fail cleanly rather than
    // being killed mid-stream.
    CURLOPT_TIMEOUT        => 170,
    CURLOPT_HEADERFUNCTION => static function ($ch, string $header): int {
        // Mirror the upstream status and the headers git needs to parse the
        // stream. Only act while our own headers are still unsent (streaming the
        // body will have committed them).
        if (!headers_sent()) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $m)) {
                http_response_code((int)$m[1]);
            } elseif (stripos($header, 'Content-Type:') === 0
                   || stripos($header, 'Content-Encoding:') === 0
                   || stripos($header, 'Cache-Control:') === 0) {
                header(trim($header), true);
            }
        }
        return strlen($header);
    },
    CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk): int {
        echo $chunk;
        flush();
        return strlen($chunk);
    },
]);

if ($method === 'POST') {
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
}

// Tear down PHP output buffering so chunks reach the client as they arrive.
while (ob_get_level() > 0) {
    ob_end_flush();
}

if (curl_exec($ch) === false && !headers_sent()) {
    http_response_code(502);
    header('Content-Type: text/plain');
    echo 'upstream error: ' . curl_error($ch) . "\n";
}
curl_close($ch);
