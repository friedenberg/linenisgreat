<?php

declare(strict_types=1);

/**
 * Decorates a DataSource so that `code` HTML partials are served live from
 * GitHub (via GithubReadmeClient) when read-through is available, falling back
 * to the wrapped source's build-time partial — and ultimately to the frontend's
 * description card (app/public/code.php) — when it is not. Every other call
 * delegates unchanged.
 */
class CodeReadmeDataSource implements DataSource
{
    private DataSource $inner;
    private GithubReadmeClient $readme;

    public function __construct(DataSource $inner, GithubReadmeClient $readme)
    {
        $this->inner = $inner;
        $this->readme = $readme;
    }

    public function getCollection(string $type): array
    {
        return $this->inner->getCollection($type);
    }

    public function getItem(string $type, string $id): ?array
    {
        return $this->inner->getItem($type, $id);
    }

    public function getHtmlPartial(string $type, string $id): ?string
    {
        if ($type === 'code') {
            $live = $this->readme->fetch($id);
            if ($live !== null) {
                return $live;
            }
            // Read-through unavailable (no token / invalid name / upstream down
            // with no cache): fall through to the build-time partial below.
        }

        return $this->inner->getHtmlPartial($type, $id);
    }
}
