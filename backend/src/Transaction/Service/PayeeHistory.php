<?php

namespace App\Transaction\Service;

use App\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What the user wrote with a payee (an expense or revenue account) in the
 * last years, whatever account paid: the descriptions and categories used,
 * latest first. A bank names the merchant ("DIMENSIONE"), the user writes
 * what it was ("Fibra casa"): when the latest ones all say the same, that is
 * the description to propose (`usual`); when they differ (Amazon), the
 * latest few are offered to pick from.
 */
class PayeeHistory
{
    private const YEARS = 3;
    private const MAX = 5;
    /** How many of the latest must agree for a description to be the usual one. */
    private const USUAL = 3;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param list<string> $accountIds
     * @return array<string, array{usual: ?string, usualCategoryId: ?string, categoryId: ?string, descriptions: list<array{description: string, categoryId: ?string, uses: int}>, categories: list<string>}> by account id, only those with transactions
     */
    public function forAccounts(User $user, array $accountIds): array
    {
        $accountIds = array_values(array_unique($accountIds));
        if ($accountIds === []) {
            return [];
        }
        $rows = $this->entityManager->getConnection()->executeQuery(
            <<<SQL
                SELECT p.id AS payee, t.description, t.category_id
                FROM transaction t
                JOIN account p ON p.id IN (t.source_account_id, t.destination_account_id) AND p.type IN ('expense', 'revenue')
                WHERE t.user_id = :user AND t.deleted_at IS NULL AND p.id IN (:ids)
                  AND t.description IS NOT NULL AND t.occurred_at >= :since
                ORDER BY t.occurred_at DESC, t.created_at DESC
                SQL,
            ['user' => $user->getId()->toRfc4122(), 'ids' => $accountIds, 'since' => (new \DateTimeImmutable('-'.self::YEARS.' years'))->format('Y-m-d')],
            ['ids' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $byPayee = [];
        foreach ($rows as $row) {
            $byPayee[(string) $row['payee']][] = $row;
        }

        $result = [];
        foreach ($byPayee as $payee => $transactions) {
            $descriptions = [];
            $categories = [];
            foreach ($transactions as $row) {
                $key = mb_strtolower(trim((string) $row['description']));
                if ($key !== '' && !isset($descriptions[$key])) {
                    $descriptions[$key] = ['description' => trim((string) $row['description']), 'categoryId' => $row['category_id'], 'uses' => 0];
                }
                if ($key !== '') {
                    ++$descriptions[$key]['uses'];
                }
                if ($row['category_id'] !== null && !\in_array($row['category_id'], $categories, true)) {
                    $categories[] = (string) $row['category_id'];
                }
            }

            $latest = array_map(static fn (array $row) => mb_strtolower(trim((string) $row['description'])), \array_slice($transactions, 0, self::USUAL));
            $usual = \count($latest) >= 2 && \count(array_unique($latest)) === 1 ? $descriptions[$latest[0]] ?? null : null;

            $result[$payee] = [
                'usual' => $usual['description'] ?? null,
                'usualCategoryId' => $usual['categoryId'] ?? null,
                'categoryId' => $categories[0] ?? null,
                'descriptions' => \array_slice(array_values($descriptions), 0, self::MAX),
                'categories' => \array_slice($categories, 0, self::MAX),
            ];
        }

        return $result;
    }

    /**
     * For each of these asset accounts, the other account of the latest
     * transfer into it (`in`) or out of it (`out`): where money between the
     * user's own accounts usually comes from or goes.
     *
     * @param list<string> $assetIds
     * @return array<string, array{id: string, name: string, type: string}> by asset account id
     */
    public function lastTransferPartners(User $user, array $assetIds, string $direction): array
    {
        $assetIds = array_values(array_unique($assetIds));
        if ($assetIds === []) {
            return [];
        }
        [$mine, $theirs] = $direction === 'in' ? ['destination_account_id', 'source_account_id'] : ['source_account_id', 'destination_account_id'];
        $rows = $this->entityManager->getConnection()->executeQuery(
            <<<SQL
                SELECT DISTINCT ON (t.{$mine}) t.{$mine} AS asset, o.id, o.name, o.type
                FROM transaction t
                JOIN account o ON o.id = t.{$theirs} AND o.type = 'asset' AND o.deleted_at IS NULL
                WHERE t.user_id = :user AND t.deleted_at IS NULL AND t.{$mine} IN (:ids)
                ORDER BY t.{$mine}, t.occurred_at DESC
                SQL,
            ['user' => $user->getId()->toRfc4122(), 'ids' => $assetIds],
            ['ids' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $partners = [];
        foreach ($rows as $row) {
            $partners[(string) $row['asset']] = ['id' => (string) $row['id'], 'name' => (string) $row['name'], 'type' => (string) $row['type']];
        }

        return $partners;
    }
}
