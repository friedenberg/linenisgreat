<?php

declare(strict_types=1);

/**
 * Render a collection (as the API serves it) into an Atom 1.0 or RSS 2.0 feed.
 * Data-driven across types like Card\CardRenderer: each item is collapsed onto
 * a normalized entry (id / title / link / updated / summary / categories /
 * haystack), optionally filtered by a FeedQuery, then serialized.
 *
 * Item links point back at the human site (SITE_BASE_URL, default
 * https://linenisgreat.com) under /<type>/<id>.
 */
class FeedBuilder
{
    private string $siteBase;

    public function __construct()
    {
        $this->siteBase = rtrim(
            getenv('SITE_BASE_URL') ?: 'https://linenisgreat.com',
            '/',
        );
    }

    /**
     * @param string $type API collection name (e.g. 'events').
     * @param array<int|string,mixed> $items The collection's `data`.
     * @param string $format 'atom' | 'rss'.
     * @param string $selfUrl Absolute URL this feed is served at (for rel=self).
     * @param FeedQuery $query Active query filter (empty = all items).
     */
    public function build(
        string $type,
        array $items,
        string $format,
        string $selfUrl,
        FeedQuery $query,
    ): string {
        $entries = [];

        foreach ($items as $key => $item) {
            if (!is_array($item)) {
                continue;
            }

            $entry = $this->normalize($type, (string) $key, $item);

            if (!$query->isEmpty() && !$query->matches($entry['haystack'])) {
                continue;
            }

            $entries[] = $entry;
        }

        return $format === 'rss'
            ? $this->renderRss($type, $entries, $selfUrl)
            : $this->renderAtom($type, $entries, $selfUrl);
    }

    /**
     * Collapse one item onto the normalized entry shape.
     *
     * @param array<string,mixed> $item
     * @return array{id:string,title:string,link:string,updated:int,summary:string,categories:array<int,string>,haystack:string}
     */
    private function normalize(string $type, string $key, array $item): array
    {
        $id = (string) ($item['object-id'] ?? $item['id'] ?? $item['objectId'] ?? $key);

        $title = (string) (
            $item['summary']
            ?? $item['title']
            ?? $item['bezeichnung']
            ?? $this->firstLine((string) ($item['description'] ?? ''))
            ?: $id
        );

        $rawDate = (string) ($item['date'] ?? $item['dtstart'] ?? '');
        $updated = $this->toTimestamp($rawDate);

        $summary = (string) ($item['description'] ?? '');

        // Events carry time/place that the bare description lacks; surface them.
        if ($type === 'events') {
            $when = $this->formatWhen($item['dtstart'] ?? null, $item['dtend'] ?? null);
            $where = (string) ($item['location'] ?? '');
            $prefix = trim(($when !== '' ? "{$when}" : '') . ($where !== '' ? " · {$where}" : ''));
            if ($prefix !== '') {
                $summary = $prefix . "\n\n" . $summary;
            }
        }

        $tags = $item['tags'] ?? [];
        $categories = is_array($tags) ? array_map('strval', $tags) : [];

        $haystack = strtolower(trim(implode(' ', [
            $title,
            $id,
            (string) ($item['description'] ?? ''),
            (string) ($item['location'] ?? ''),
            implode(' ', $categories),
        ])));

        return [
            'id' => $id,
            'title' => $title,
            'link' => "{$this->siteBase}/{$type}/{$id}",
            'updated' => $updated,
            'summary' => $summary,
            'categories' => $categories,
            'haystack' => $haystack,
        ];
    }

    /**
     * @param array<int,array{id:string,title:string,link:string,updated:int,summary:string,categories:array<int,string>,haystack:string}> $entries
     */
    private function renderAtom(string $type, array $entries, string $selfUrl): string
    {
        $updated = $this->feedUpdated($entries);
        $alternate = "{$this->siteBase}/{$type}";

        $out = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $out .= '<feed xmlns="http://www.w3.org/2005/Atom">' . "\n";
        $out .= '  <title>' . $this->esc("{$type} — linenisgreat.com") . "</title>\n";
        $out .= '  <id>' . $this->esc($selfUrl) . "</id>\n";
        $out .= '  <link rel="self" href="' . $this->esc($selfUrl) . "\"/>\n";
        $out .= '  <link rel="alternate" href="' . $this->esc($alternate) . "\"/>\n";
        $out .= '  <updated>' . gmdate('Y-m-d\TH:i:s\Z', $updated) . "</updated>\n";

        foreach ($entries as $e) {
            $out .= "  <entry>\n";
            $out .= '    <title>' . $this->esc($e['title']) . "</title>\n";
            $out .= '    <id>' . $this->esc($e['link']) . "</id>\n";
            $out .= '    <link rel="alternate" href="' . $this->esc($e['link']) . "\"/>\n";
            $out .= '    <updated>' . gmdate('Y-m-d\TH:i:s\Z', $e['updated']) . "</updated>\n";
            $out .= '    <summary>' . $this->esc($e['summary']) . "</summary>\n";
            foreach ($e['categories'] as $c) {
                $out .= '    <category term="' . $this->esc($c) . "\"/>\n";
            }
            $out .= "  </entry>\n";
        }

        $out .= "</feed>\n";

        return $out;
    }

    /**
     * @param array<int,array{id:string,title:string,link:string,updated:int,summary:string,categories:array<int,string>,haystack:string}> $entries
     */
    private function renderRss(string $type, array $entries, string $selfUrl): string
    {
        $updated = $this->feedUpdated($entries);
        $alternate = "{$this->siteBase}/{$type}";

        $out = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $out .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
        $out .= "  <channel>\n";
        $out .= '    <title>' . $this->esc("{$type} — linenisgreat.com") . "</title>\n";
        $out .= '    <link>' . $this->esc($alternate) . "</link>\n";
        $out .= '    <description>' . $this->esc("{$type} from linenisgreat.com") . "</description>\n";
        $out .= '    <atom:link href="' . $this->esc($selfUrl) . '" rel="self" type="application/rss+xml"/>' . "\n";
        $out .= '    <lastBuildDate>' . gmdate('D, d M Y H:i:s', $updated) . " GMT</lastBuildDate>\n";

        foreach ($entries as $e) {
            $out .= "    <item>\n";
            $out .= '      <title>' . $this->esc($e['title']) . "</title>\n";
            $out .= '      <link>' . $this->esc($e['link']) . "</link>\n";
            $out .= '      <guid isPermaLink="true">' . $this->esc($e['link']) . "</guid>\n";
            $out .= '      <pubDate>' . gmdate('D, d M Y H:i:s', $e['updated']) . " GMT</pubDate>\n";
            $out .= '      <description>' . $this->esc($e['summary']) . "</description>\n";
            foreach ($e['categories'] as $c) {
                $out .= '      <category>' . $this->esc($c) . "</category>\n";
            }
            $out .= "    </item>\n";
        }

        $out .= "  </channel>\n";
        $out .= "</rss>\n";

        return $out;
    }

    /**
     * @param array<int,array{updated:int}> $entries
     */
    private function feedUpdated(array $entries): int
    {
        $max = 0;
        foreach ($entries as $e) {
            if ($e['updated'] > $max) {
                $max = $e['updated'];
            }
        }

        return $max > 0 ? $max : time();
    }

    private function toTimestamp(string $value): int
    {
        if ($value === '') {
            return time();
        }

        $ts = strtotime($value);

        return $ts !== false ? $ts : time();
    }

    private function firstLine(string $value): string
    {
        $line = strtok($value, "\n");

        return $line === false ? '' : trim($line);
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Humanize a dtstart/dtend pair for the entry summary. Kept defensive —
     * feeds must never fatal — and in step with Event::formatWhen / Card\
     * CardRenderer::formatWhen.
     */
    private function formatWhen(?string $start, ?string $end): string
    {
        if ($start === null || $start === '') {
            return '';
        }

        try {
            $startDt = new DateTimeImmutable($start);
        } catch (Exception $e) {
            return (string) $start;
        }

        $day = $startDt->format('D M j, Y');
        $startTime = $startDt->format('g:i A');

        if ($end === null || $end === '') {
            return "{$day}, {$startTime}";
        }

        try {
            $endDt = new DateTimeImmutable($end);
        } catch (Exception $e) {
            return "{$day}, {$startTime}";
        }

        $endTime = $endDt->format('g:i A');

        if ($startDt->format('Y-m-d') === $endDt->format('Y-m-d')) {
            return "{$day}, {$startTime} – {$endTime}";
        }

        return "{$day}, {$startTime} – " . $endDt->format('D M j, Y') . ", {$endTime}";
    }
}
