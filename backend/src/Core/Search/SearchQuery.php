<?php

namespace App\Core\Search;

/**
 * What the user typed in the search box, split the way search boxes usually
 * read it: words separated by spaces, a "quoted phrase" kept together, and a
 * leading "-" to exclude a word or phrase (`kfc -"menu bambini"`).
 *
 * Every included term has to appear somewhere in the searched text and no
 * excluded term may; matching is on part of a word, ignoring case and accents
 * (Postgres `unaccent`), so `caffe` finds "Caffè" and `/3` finds "1/3".
 */
final readonly class SearchQuery
{
    /**
     * @param string[] $include
     * @param string[] $exclude
     */
    private function __construct(
        public array $include,
        public array $exclude,
    ) {
    }

    /** Null when there is nothing to search for (empty or only spaces, quotes and dashes). */
    public static function parse(?string $query): ?self
    {
        if ($query === null) {
            return null;
        }

        preg_match_all('/(?<not>-?)(?:"(?<phrase>[^"]*)"|(?<word>\S+))/u', $query, $matches, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL);

        $include = [];
        $exclude = [];
        foreach ($matches as $match) {
            // A stray quote in a word (`"kfc`) is not part of what's searched.
            $term = trim($match['phrase'] ?? str_replace('"', '', $match['word']));

            // A lone dash ("Amazon - Telefono") is punctuation, not a term.
            if ($term === '' || $term === '-') {
                continue;
            }

            if ($match['not'] === '-') {
                $exclude[] = $term;
            } else {
                $include[] = $term;
            }
        }

        if ($include === [] && $exclude === []) {
            return null;
        }

        return new self(array_values(array_unique($include)), array_values(array_unique($exclude)));
    }

    /**
     * The native SQL condition matching this query against $haystack (an SQL
     * text expression, already passed through `unaccent()`), with its
     * parameters, named after $prefix so several conditions can share a query.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public function toSql(string $haystack, string $prefix): array
    {
        $conditions = [];
        $params = [];

        foreach (['include' => $this->include, 'exclude' => $this->exclude] as $kind => $terms) {
            foreach ($terms as $index => $term) {
                $name = \sprintf('%s_%s%d', $prefix, $kind[0], $index);
                $operator = $kind === 'include' ? 'ILIKE' : 'NOT ILIKE';
                $conditions[] = "{$haystack} {$operator} unaccent(CAST(:{$name} AS text))";
                // LIKE's own wildcards and escape character are searched as typed.
                $params[$name] = '%'.addcslashes($term, '\\%_').'%';
            }
        }

        return ['('.implode(' AND ', $conditions).')', $params];
    }
}
