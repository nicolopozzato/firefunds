<?php

namespace App\Transaction\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What the user did last time with the same words: the latest transaction
 * of each description (by `key()`) in the last years, its two sides and
 * category; a draft recorded counts with the words it came with too, so
 * "DIMENSIONE" still leads to its payee once written "Fibra casa". Used to guess the other side and category of what comes from
 * outside (a bank's file, a notification), in one query for all of it.
 */
class DescriptionHistory
{
    private const YEARS = 3;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array<string, array{source: ?array{id: string, name: string, type: string}, destination: ?array{id: string, name: string, type: string}, categoryId: ?string}> key => latest
     */
    public function forUser(User $user): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            <<<SQL
                SELECT DISTINCT ON (lower(h.description)) h.description,
                       s.id AS source_id, s.name AS source_name, s.type AS source_type,
                       d.id AS destination_id, d.name AS destination_name, d.type AS destination_type,
                       h.category_id
                FROM (
                    SELECT t.description, t.source_account_id, t.destination_account_id, t.category_id, t.occurred_at
                    FROM transaction t
                    WHERE t.user_id = :user AND t.deleted_at IS NULL AND t.description IS NOT NULL AND t.occurred_at >= :since
                    UNION ALL
                    -- What a recorded draft said (a bank's merchant), whatever the user then wrote instead.
                    SELECT dr.description, t.source_account_id, t.destination_account_id, t.category_id, t.occurred_at
                    FROM draft dr
                    JOIN transaction t ON t.id = (dr.transaction_ids->>0)::uuid AND t.deleted_at IS NULL
                    WHERE dr.user_id = :user AND dr.status = 'accepted' AND dr.description IS NOT NULL AND t.occurred_at >= :since
                ) h
                LEFT JOIN account s ON s.id = h.source_account_id AND s.deleted_at IS NULL
                LEFT JOIN account d ON d.id = h.destination_account_id AND d.deleted_at IS NULL
                ORDER BY lower(h.description), h.occurred_at DESC
                SQL,
            ['user' => $user->getId()->toRfc4122(), 'since' => (new \DateTimeImmutable('-'.self::YEARS.' years'))->format('Y-m-d')],
        );

        $history = [];
        foreach ($rows as $row) {
            $key = self::key((string) $row['description']);
            if ($key === '' || isset($history[$key])) {
                continue;
            }
            $side = static fn (string $prefix) => $row[$prefix.'_id'] !== null
                ? ['id' => (string) $row[$prefix.'_id'], 'name' => (string) $row[$prefix.'_name'], 'type' => (string) $row[$prefix.'_type']]
                : null;
            $history[$key] = ['source' => $side('source'), 'destination' => $side('destination'), 'categoryId' => $row['category_id']];
        }

        return $history;
    }

    /**
     * What stays of a bank's description once what changes every time is
     * gone: digits, dates, card numbers, punctuation ("POS 12/10 ESSELUNGA
     * MILANO CARTA *1234" and "POS 03/11 ESSELUNGA MILANO CARTA *1234" are
     * the same shop). Accents go too ("Nicolò", "Nicolo'", "Nicolo").
     */
    public static function key(string $text): string
    {
        $text = mb_strtolower((string) preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($text, \Normalizer::FORM_D)));
        $text = (string) preg_replace('/[^\p{L}\s]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Whether one key holds the other as whole words ("esselunga" in
     * "esselunga milano"); the shorter needs some letters, or "bar" would be
     * in every bar.
     */
    public static function contains(string $haystack, string $needle): bool
    {
        if ($needle === '' || mb_strlen($needle) < 5) {
            return false;
        }

        return str_contains(' '.$haystack.' ', ' '.$needle.' ');
    }
}
