<?php

declare(strict_types=1);

class ApiResponse
{
    private string $allowedOrigin;

    public function __construct(string $allowedOrigin = 'https://linenisgreat.com')
    {
        $this->allowedOrigin = $allowedOrigin;
    }

    public function sendJson(mixed $data, string $type, int $statusCode = 200): void
    {
        $this->setCorsHeaders();
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        $count = is_array($data) ? count($data) : 1;

        echo json_encode([
            'data' => $data,
            'meta' => [
                'count' => $count,
                'type' => $type,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function sendHtml(string $html, int $statusCode = 200): void
    {
        $this->setCorsHeaders();
        http_response_code($statusCode);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
    }

    /**
     * Serve an iCalendar document. `Content-Disposition: attachment` forces a
     * download for the plain `ics` link (the HTML `download` attribute is
     * ignored cross-origin, so the header is what makes it save rather than
     * render); calendar clients reaching the same resource over `webcal://`
     * ignore the disposition and subscribe/import as usual.
     */
    public function sendCalendar(string $ics, string $filename, int $statusCode = 200): void
    {
        $this->setCorsHeaders();
        http_response_code($statusCode);
        header('Content-Type: text/calendar; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        echo $ics;
    }

    public function sendRedirect(string $url, int $statusCode = 302): void
    {
        $this->setCorsHeaders();
        http_response_code($statusCode);
        header("Location: {$url}");
    }

    public function sendNotFound(string $message = 'Not found'): void
    {
        $this->setCorsHeaders();
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'error' => $message,
        ]);
    }

    public function sendOptionsResponse(): void
    {
        $this->setCorsHeaders();
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
        http_response_code(204);
    }

    private function setCorsHeaders(): void
    {
        header("Access-Control-Allow-Origin: {$this->allowedOrigin}");
    }
}
