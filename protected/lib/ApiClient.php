<?php

declare(strict_types=1);

class ApiClient
{
    private string $endpoint;
    private string $baseUrl;
    private ?array $raw = null;

    /**
     * @param string $endpoint API endpoint name (e.g., 'objects', 'yoga', 'code')
     */
    public function __construct(string $endpoint)
    {
        $this->endpoint = $endpoint;
        $this->baseUrl = rtrim(
            getenv('API_BASE_URL') ?: 'https://api.linenisgreat.com',
            '/',
        );
    }

    /**
     * @return array
     */
    public function getRaw(): array
    {
        if ($this->raw !== null) {
            return $this->raw;
        }

        $url = "{$this->baseUrl}/{$this->endpoint}";
        $response = file_get_contents($url);

        if ($response === false) {
            throw new Exception("Failed to fetch from API: {$url}");
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded) || !isset($decoded['data'])) {
            throw new Exception("Invalid API response from: {$url}");
        }

        $this->raw = $decoded['data'];

        return $this->raw;
    }

    /**
     * @param string $className
     * @param string|null $urlPrefix
     * @return array
     */
    public function parseCustomClass(string $className, ?string $urlPrefix = null): array
    {
        return array_map(
            function ($c) use ($className, $urlPrefix) {
                if ($urlPrefix !== null) {
                    return new $className($c, $urlPrefix);
                }

                return new $className($c);
            },
            $this->getRaw(),
        );
    }

    /**
     * @param string|null $urlPrefix
     * @param string $className
     * @return array
     */
    public function parse(?string $urlPrefix = null, string $className = 'Zettel'): array
    {
        return $this->parseCustomClass($className, $urlPrefix);
    }

    /**
     * @param string $objectId
     * @return string
     */
    public function getHtmlPartial(string $objectId): string
    {
        $url = "{$this->baseUrl}/{$this->endpoint}/{$objectId}/html";
        $response = file_get_contents($url);

        if ($response === false) {
            throw new Exception("Failed to fetch HTML partial from API: {$url}");
        }

        return $response;
    }
}
