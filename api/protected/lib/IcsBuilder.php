<?php

declare(strict_types=1);

/**
 * Build a single-VEVENT iCalendar (RFC 5545) document from an `!event` item as
 * the API serves it. The `!event` dodder type is a TOML/human representation of
 * a CalDAV VEVENT; this turns that flat shape back into the wire format a
 * calendar client (or the OS "add to cal" / webcal handler) consumes.
 *
 * Timestamps in the data carry an explicit offset (e.g. 2026-06-20T06:30:00
 * -06:00); they are normalized to UTC (`Ymd\THis\Z`) so the VEVENT is
 * timezone-unambiguous without shipping a VTIMEZONE component.
 */
class IcsBuilder
{
    /** Product identifier advertised in the calendar (RFC 5545 PRODID). */
    private const PRODID = '-//linenisgreat.com//events//EN';

    /**
     * @param array<string,mixed> $event The event item (API shape).
     * @param string $id The collection key / object id, used for the UID.
     */
    public function build(array $event, string $id): string
    {
        $uid = $this->extractId($event, $id) . '@linenisgreat.com';

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:' . self::PRODID,
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . $this->escape($uid),
            'DTSTAMP:' . $this->toUtc($event['date'] ?? 'now'),
        ];

        if (isset($event['dtstart'])) {
            $lines[] = 'DTSTART:' . $this->toUtc((string) $event['dtstart']);
        }

        if (isset($event['dtend'])) {
            $lines[] = 'DTEND:' . $this->toUtc((string) $event['dtend']);
        }

        $summary = $event['summary'] ?? $event['title'] ?? $event['description'] ?? '';
        if ($summary !== '') {
            $lines[] = 'SUMMARY:' . $this->escape((string) $summary);
        }

        if (!empty($event['description'])) {
            $lines[] = 'DESCRIPTION:' . $this->escape((string) $event['description']);
        }

        if (!empty($event['location'])) {
            $lines[] = 'LOCATION:' . $this->escape((string) $event['location']);
        }

        if (!empty($event['url'])) {
            $lines[] = 'URL:' . $this->escape((string) $event['url']);
        }

        if (!empty($event['organizer'])) {
            $lines[] = 'ORGANIZER:' . $this->escape((string) $event['organizer']);
        }

        $tags = $event['tags'] ?? [];
        if (is_array($tags) && $tags !== []) {
            $lines[] = 'CATEGORIES:' . $this->escape(implode(',', $tags));
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        // RFC 5545 requires CRLF line endings.
        return implode("\r\n", array_map([$this, 'fold'], $lines)) . "\r\n";
    }

    /**
     * Object-id with the same fallback chain the rest of the API uses.
     *
     * @param array<string,mixed> $event
     */
    private function extractId(array $event, string $default): string
    {
        return (string) ($event['object-id'] ?? $event['id'] ?? $event['objectId'] ?? $default);
    }

    /**
     * Normalize an ISO-8601 timestamp (with offset) to a UTC iCalendar
     * date-time, `Ymd\THis\Z`. Unparseable input falls back to "now" so the
     * VEVENT stays valid rather than emitting a malformed DTSTAMP.
     */
    private function toUtc(string $value): string
    {
        try {
            $dt = new DateTimeImmutable($value);
        } catch (Exception $e) {
            $dt = new DateTimeImmutable('now');
        }

        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /**
     * Escape a TEXT value per RFC 5545 §3.3.11: backslash, comma, semicolon,
     * and newlines.
     */
    private function escape(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace([',', ';'], ['\\,', '\\;'], $value);
        $value = preg_replace('/\r\n|\r|\n/', '\\n', $value);

        return $value;
    }

    /**
     * Fold content lines longer than 75 octets (RFC 5545 §3.1): split into
     * 75-octet chunks joined by CRLF + a single leading space.
     */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $folded = '';
        $remaining = $line;
        $first = true;

        while ($remaining !== '') {
            $limit = $first ? 75 : 74;
            $chunk = substr($remaining, 0, $limit);
            $folded .= ($first ? '' : "\r\n ") . $chunk;
            $remaining = substr($remaining, $limit);
            $first = false;
        }

        return $folded;
    }
}
