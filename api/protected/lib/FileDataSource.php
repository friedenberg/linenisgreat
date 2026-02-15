<?php

declare(strict_types=1);

class FileDataSource implements DataSource
{
    private string $dataDir;
    private array $cache = [];

    public function __construct(string $dataDir)
    {
        $this->dataDir = rtrim($dataDir, '/');
    }

    public function getCollection(string $type): array
    {
        return $this->loadJson($type);
    }

    public function getItem(string $type, string $id): ?array
    {
        $data = $this->loadJson($type);

        if (isset($data[$id])) {
            return $data[$id];
        }

        // For array-indexed collections, search by object-id
        foreach ($data as $item) {
            if (is_array($item)) {
                $itemId = $item['object-id'] ?? $item['id'] ?? $item['objectId'] ?? null;
                if ($itemId === $id) {
                    return $item;
                }
            }
        }

        return null;
    }

    public function getHtmlPartial(string $type, string $id): ?string
    {
        $path = "{$this->dataDir}/{$type}/{$id}/index.html";

        if (!file_exists($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents !== false ? $contents : null;
    }

    private function loadJson(string $type): array
    {
        if (isset($this->cache[$type])) {
            return $this->cache[$type];
        }

        $filename = str_replace('-', '_', $type);
        $path = "{$this->dataDir}/{$filename}.json";

        if (!file_exists($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        $data = json_decode($contents, true);

        if (!is_array($data)) {
            return [];
        }

        $this->cache[$type] = $data;

        return $data;
    }
}
