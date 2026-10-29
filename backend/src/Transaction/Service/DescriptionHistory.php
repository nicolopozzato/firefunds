<?php

namespace App\Transaction\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What the user did last time with the same words: the latest transaction
 * of each description (by `key()`) in the last years, its two sides and
 * category. Used to guess the other side and category of what comes from
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
                SELECT DISTINCT ON (lower(t.description)) t.description,
                       s.id AS source_id, s.name AS source_name, s.type AS source_type,
                       d.id AS destination_id, d.name AS destination_name, d.type AS destination_type,
                       t.category_id
                FROM transaction t
                LEFT JOIN account s ON s.id = t.source_account_id AND s.deleted_at IS NULL
                LEFT JOIN account d ON d.id = t.destination_account_id AND d.deleted_at IS NULL
                WHERE t.user_id = :user AND t.deleted_at IS NULL AND t.description IS NOT NULL
                  AND t.occurred_at >= :since
                ORDER BY lower(t.description), t.occurred_at DESC
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
     * the same shop).
     */
    public static function key(string $text): string
    {
        $text = mb_strtolower($text);
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
