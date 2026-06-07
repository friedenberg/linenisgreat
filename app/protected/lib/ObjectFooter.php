<?php

declare(strict_types=1);

/**
 * Framework-level object footer: a "last updated" line above a pipe-separated
 * row of format / link entries, rendered by the object_footer template at the
 * bottom of a full object view.
 *
 * Each link is `{label, href, download}`; a `download` link gets the HTML
 * download attribute (for same-origin resources) — cross-origin downloads are
 * forced by the server's Content-Disposition instead. The first link omits its
 * leading separator (`sep`).
 *
 * Consumers: code detail pages (github | license) and event detail pages
 * (ics | add to cal). New types attach a footer by calling Route*::setFooter
 * with their own updated string + links.
 */
class ObjectFooter
{
    /**
     * @param string|null $updated Human last-updated string, or null to omit.
     * @param array<int,array{label:string,href:string,download?:bool}> $links
     * @return array<string,mixed> Template variables for object_footer.
     */
    public static function build(?string $updated, array $links): array
    {
        $built = [];

        foreach (array_values($links) as $i => $link) {
            $built[] = [
                'label' => $link['label'],
                'href' => $link['href'],
                'download' => !empty($link['download']),
                'sep' => $i > 0,
            ];
        }

        return [
            'updated' => ($updated === null || $updated === '') ? null : $updated,
            'links' => $built,
            'has_links' => $built !== [],
        ];
    }
}
