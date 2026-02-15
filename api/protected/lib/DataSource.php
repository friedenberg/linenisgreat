<?php

declare(strict_types=1);

interface DataSource
{
    /**
     * Get all items in a collection.
     *
     * @param string $type
     * @return array
     */
    public function getCollection(string $type): array;

    /**
     * Get a single item by type and ID.
     *
     * @param string $type
     * @param string $id
     * @return array|null
     */
    public function getItem(string $type, string $id): ?array;

    /**
     * Get an HTML partial for an object.
     *
     * @param string $type
     * @param string $id
     * @return string|null
     */
    public function getHtmlPartial(string $type, string $id): ?string;
}
