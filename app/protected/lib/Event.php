<?php

declare(strict_types=1);

/**
 * The `!event` dodder type: a TOML/human representation of a CalDAV VEVENT.
 * Renders as a card (card_event) on the /events index and supports a full
 * object view (events.php detail branch) with an ics / add-to-cal footer.
 *
 * Mirrors Card\CardRenderer::mapEvent so the live card and the API's og-image
 * card stay in lockstep.
 */
class Event
{
    use FieldMappingTrait;

    public $summary;
    public $description;
    public $location;
    public $dtstart;
    public $dtend;
    public $when;
    public $tags;
    public $type;
    public $objectId;
    public $date;
    public $url;
    public $organizer;

    public $fields;
    public $card_body_template;
    public $search_string;
    public $search_array;
    public $html;
    public $css;
    public $card_body;

    /**
     * @param array $j
     * @param string $urlPrefix
     */
    public function __construct($j, $urlPrefix = "")
    {
        $this->summary = $j['summary'] ?? $j['title'] ?? "";
        $this->description = $j['description'] ?? "";
        $this->location = $j['location'] ?? "";
        $this->dtstart = $j['dtstart'] ?? "";
        $this->dtend = $j['dtend'] ?? "";
        $this->type = $j['type'] ?? "!event";
        $this->date = $j['date'] ?? "";
        $this->organizer = $j['organizer'] ?? "";
        $this->objectId = $this->extractObjectId($j);

        $this->when = self::formatWhen($this->dtstart, $this->dtend);

        $tags = $this->normalizeTags($j['tags'] ?? []);
        $this->tags = $tags;

        $this->card_body_template = "card_event";
        $this->url = $this->buildUrl($urlPrefix, $this->objectId, $this->summary);

        $this->fields = [];
        if ($this->location !== "") {
            $this->fields[] = ['key' => "where", 'value' => $this->location];
        }
        if ($tags !== "") {
            $this->fields[] = ['key' => "tags", 'value' => $tags];
        }

        $this->search_string = trim(
            "$this->summary $this->objectId $this->description "
            . "$this->location $this->when $tags",
        );
        $this->search_array = $this->buildSearchArray($this->search_string);
    }

    /**
     * Format a dtstart/dtend pair (ISO-8601 with offset) into a compact human
     * range, e.g. "Sat Jun 20, 2026, 6:30 AM – 9:00 AM". Unparseable / missing
     * input degrades to the raw start string. Kept identical to
     * Card\CardRenderer::formatWhen.
     */
    public static function formatWhen(?string $start, ?string $end): string
    {
        if ($start === null || $start === "") {
            return "";
        }

        try {
            $startDt = new DateTimeImmutable($start);
        } catch (Exception $e) {
            return $start;
        }

        $day = $startDt->format('D M j, Y');
        $startTime = $startDt->format('g:i A');

        if ($end === null || $end === "") {
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
