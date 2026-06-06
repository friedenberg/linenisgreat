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

    public function sendRedirect(string $url, int $statusCode = 302): void
    {
        $this->setCorsHeaders();
        http_response_code($statusCode);
        header("Location: {$url}");
    }

    public function sendNotFound(string $message = 'Not found'): void
    {
        $this->sendError($message, 404);
    }

    /**
     * Relay blob bytes from a backend (e.g. madder serve) verbatim. Content-
     * addressed, so the bytes for a digest never change — mark them immutable
     * so callers and caches can keep them forever.
     */
    public function sendBlob(string $body, string $contentType, int $statusCode = 200): void
    {
        $this->setCorsHeaders();
        http_response_code($statusCode);
        header("Content-Type: {$contentType}");
        header('Cache-Control: public, max-age=31536000, immutable');
        echo $body;
    }

    public function sendError(string $message, int $statusCode): void
    {
        $this->setCorsHeaders();
        http_response_code($statusCode);
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
