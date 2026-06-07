<?php

declare(strict_types=1);

/**
 * Server-side reimplementation of the frontend search grammar
 * (app/public/assets/javascript.js) so a feed link can inherit the active query
 * via ?q=. Tokens are whitespace-separated and matched as case-insensitive
 * substrings against an item's haystack; `not <t>` negates a clause and
 * `<a> or <b>` makes the two terms alternatives within one clause. Clauses are
 * AND-ed. An empty query matches everything.
 */
class FeedQuery
{
    /** @var array<int,array{alts:array<int,string>,negate:bool}> */
    private array $clauses;

    public function __construct(string $query)
    {
        $this->clauses = self::parse($query);
    }

    public function isEmpty(): bool
    {
        return $this->clauses === [];
    }

    /**
     * @param string $haystack Already-lowercased searchable text for one item.
     */
    public function matches(string $haystack): bool
    {
        foreach ($this->clauses as $clause) {
            $hit = false;
            foreach ($clause['alts'] as $alt) {
                if ($alt !== '' && str_contains($haystack, $alt)) {
                    $hit = true;
                    break;
                }
            }

            if ($clause['negate'] ? $hit : !$hit) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int,array{alts:array<int,string>,negate:bool}>
     */
    private static function parse(string $query): array
    {
        $tokens = preg_split('/\s+/', strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === false || $tokens === []) {
            return [];
        }

        $clauses = [];
        $pendingOr = false;
        $pendingNot = false;

        foreach ($tokens as $token) {
            if ($token === 'or') {
                $pendingOr = $clauses !== [];
                continue;
            }

            if ($token === 'not') {
                $pendingNot = true;
                continue;
            }

            if ($pendingOr) {
                $clauses[count($clauses) - 1]['alts'][] = $token;
                $pendingOr = false;
                continue;
            }

            $clauses[] = ['alts' => [$token], 'negate' => $pendingNot];
            $pendingNot = false;
        }

        return $clauses;
    }
}
