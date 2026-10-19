<?php

namespace App\Transaction\Service;

use App\Core\Pagination\PaginatedResponse;
use App\Core\Pagination\PaginationParams;
use App\Core\Search\SearchQuery;
use App\Entity\Account;
use App\Entity\User;
use App\Repository\InvestmentTransactionRepository;
use App\Repository\TransactionRepository;
use App\Transaction\DTO\TransactionResponse;

/**
 * The transactions list, money transactions and trades together: one query
 * pages through both tables' ids, then each half is loaded with its relations
 * in a single query (no query per row).
 */
final readonly class TransactionListService
{
    public function __construct(
        private TransactionRepository $transactionRepository,
        private InvestmentTransactionRepository $investmentTransactionRepository,
    ) {
    }

    /**
     * "All transactions" mixes plain money transactions with investment BUY/SELLs
     * (a deliberately separate entity, see InvestmentTransaction), ordered together
     * by date. The ids+ordering come from one SQL UNION query, then each half is
     * hydrated via its own repository and put back in that order.
     *
     * @param array{kind: string, from: ?\DateTimeImmutable, to: ?\DateTimeImmutable, account: ?Account, search: ?SearchQuery} $filter
     */
    public function page(User $user, array $filter, PaginationParams $params): PaginatedResponse
    {
        [$rows, $total] = $this->transactionRepository->findAllKindIdsPaginated(
            $user,
            $filter['from'],
            $filter['to'],
            $params,
            $filter['account'],
            ...self::halves($filter['kind']),
            search: $filter['search'],
        );

        $moneyIds = array_column(array_filter($rows, static fn (array $row) => $row[1] === 'money'), 0);
        $investmentIds = array_column(array_filter($rows, static fn (array $row) => $row[1] === 'investment'), 0);

        $moneyById = [];
        foreach ($this->transactionRepository->findWithRelationsByIds($moneyIds) as $transaction) {
            $moneyById[(string) $transaction->getId()] = $transaction;
        }

        $investmentById = [];
        foreach ($this->investmentTransactionRepository->findWithRelationsByIds($investmentIds) as $transaction) {
            $investmentById[(string) $transaction->getId()] = $transaction;
        }

        $items = [];
        foreach ($rows as [$id, $source]) {
            $items[] = $source === 'money'
                ? TransactionResponse::fromEntity($moneyById[$id])
                : TransactionResponse::fromInvestmentEntity($investmentById[$id]);
        }

        return PaginatedResponse::create($items, $total, $params);
    }

    /**
     * Which halves of the merged list a kind keeps, as the repository's
     * includeMoney / includeInvestment / moneyKind arguments.
     *
     * @return array{includeMoney: bool, includeInvestment: bool, moneyKind: ?string}
     */
    public static function halves(string $kind): array
    {
        $moneyKind = \in_array($kind, ['withdrawal', 'deposit', 'transfer'], true) ? $kind : null;

        return [
            'includeMoney' => $kind !== 'trade',
            'includeInvestment' => \in_array($kind, ['all', 'trade'], true),
            'moneyKind' => $moneyKind,
        ];
    }
}
