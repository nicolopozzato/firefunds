<?php

namespace App\Transaction\Controller;

use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Core\Pagination\PaginatedResponse;
use App\Core\Pagination\PaginationParams;
use App\Core\Period\UserDayRange;
use App\Core\Search\SearchQuery;
use App\Entity\Account;
use App\Entity\Category;
use App\Entity\User;
use App\Repository\AccountRepository;
use App\Repository\CategoryRepository;
use App\Repository\InvestmentTransactionRepository;
use App\Repository\TransactionRepository;
use App\Transaction\DTO\CreateTransactionRequest;
use App\Transaction\DTO\DateRangeResponse;
use App\Transaction\DTO\DistributionEntryResponse;
use App\Transaction\DTO\DistributionsResponse;
use App\Transaction\DTO\TransactionAuditLogResponse;
use App\Transaction\DTO\TransactionResponse;
use App\Transaction\Service\TransactionService;
use App\Repository\TransactionAuditLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/transactions')]
class TransactionController extends AbstractController
{
    public function __construct(
        private readonly TransactionRepository $transactionRepository,
        private readonly TransactionService $transactionService,
        private readonly AccountRepository $accountRepository,
        private readonly TransactionAuditLogRepository $transactionAuditLogRepository,
        private readonly InvestmentTransactionRepository $investmentTransactionRepository,
        private readonly CategoryRepository $categoryRepository,
    ) {
    }

    /**
     * `kind` = all | withdrawal | deposit | transfer | trade (investment
     * BUY/SELLs only) | cash (money transactions only, any kind); optional
     * `from`/`to`, `accountId` and `q`, a search (see SearchQuery).
     * `categoryId` (a category's id, or `none` for no category) narrows a
     * plain kind (withdrawal, deposit, transfer) without a search, e.g. a
     * report's cell.
     */
    #[Route('', name: 'api_transactions_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        $filter = $this->parseListFilter($request, $user);
        $params = PaginationParams::fromRequest($request, $user);
        $isPlainKind = $filter['search'] === null && \in_array($filter['kind'], ['withdrawal', 'deposit', 'transfer'], true);
        $category = $this->parseCategoryFilter($request, $user);
        if ($category !== null && !$isPlainKind) {
            throw new BadRequestHttpException('A category narrows a withdrawal, deposit or transfer list without a search.');
        }

        // Plain kinds without a search keep the ORM list; anything mixing the two
        // entities, or searching, goes through the merged SQL list.
        if ($isPlainKind) {
            [$transactions, $total] = $this->transactionRepository->findByUserPaginated($user, $filter['kind'], $filter['from'], $filter['to'], $params, $filter['account'], $category);

            $items = array_map(
                static fn ($transaction) => TransactionResponse::fromEntity($transaction),
                $transactions
            );

            return $this->json(PaginatedResponse::create($items, $total, $params));
        }

        return $this->json($this->listAll($user, $filter, $params));
    }

    /**
     * What the list with the same filters adds up to, per kind (see
     * TransactionRepository::sumAllKindsByKind()), over every page.
     */
    #[Route('/totals', name: 'api_transactions_totals', methods: ['GET'])]
    public function totals(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        $filter = $this->parseListFilter($request, $user);

        return $this->json($this->transactionRepository->sumAllKindsByKind(
            $user,
            $filter['from'],
            $filter['to'],
            $filter['account'],
            ...$this->listHalves($filter['kind']),
            search: $filter['search'],
        ));
    }

    /**
     * "All transactions" mixes plain money transactions with investment BUY/SELLs
     * (a deliberately separate entity, see InvestmentTransaction), ordered together
     * by date. The ids+ordering come from one SQL UNION query, then each half is
     * hydrated via its own repository and put back in that order.
     *
     * @param array{kind: string, from: ?\DateTimeImmutable, to: ?\DateTimeImmutable, account: ?Account, search: ?SearchQuery} $filter
     */
    private function listAll(User $user, array $filter, PaginationParams $params): PaginatedResponse
    {
        [$rows, $total] = $this->transactionRepository->findAllKindIdsPaginated(
            $user,
            $filter['from'],
            $filter['to'],
            $params,
            $filter['account'],
            ...$this->listHalves($filter['kind']),
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
    private function listHalves(string $kind): array
    {
        $moneyKind = \in_array($kind, ['withdrawal', 'deposit', 'transfer'], true) ? $kind : null;

        return [
            'includeMoney' => $kind !== 'trade',
            'includeInvestment' => \in_array($kind, ['all', 'trade'], true),
            'moneyKind' => $moneyKind,
        ];
    }

    /**
     * @return array{kind: string, from: ?\DateTimeImmutable, to: ?\DateTimeImmutable, account: ?Account, search: ?SearchQuery}
     */
    private function parseListFilter(Request $request, User $user): array
    {
        $kind = $request->query->get('kind', 'all');
        if (!\in_array($kind, ['all', 'withdrawal', 'deposit', 'transfer', 'trade', 'cash'], true)) {
            throw new BadRequestHttpException('Invalid kind filter.');
        }

        [$from, $to] = UserDayRange::fromRequest($request, $this->getCurrentUser());

        $accountId = $request->query->get('accountId');
        $account = null;
        if ($accountId !== null) {
            $account = $this->accountRepository->findOneByUserAndId($user, $accountId);

            if ($account === null) {
                throw ApiException::notFound(ErrorCode::AccountNotFound);
            }
        }

        return [
            'kind' => $kind,
            'from' => $from,
            'to' => $to,
            'account' => $account,
            'search' => SearchQuery::parse($request->query->get('q')),
        ];
    }

    /** The `categoryId` filter: a category of the user, `none` (no category), or null when not given. */
    private function parseCategoryFilter(Request $request, User $user): Category|string|null
    {
        $categoryId = $request->query->get('categoryId');
        if ($categoryId === null || $categoryId === '') {
            return null;
        }
        if ($categoryId === 'none') {
            return 'none';
        }

        return $this->categoryRepository->findOneByUserAndId($user, $categoryId)
            ?? throw ApiException::notFound(ErrorCode::CategoryNotFound);
    }

    #[Route('/distributions', name: 'api_transactions_distributions', methods: ['GET'])]
    public function distributions(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        $kind = $request->query->get('kind', 'all');
        [$from, $to] = UserDayRange::fromRequest($request, $this->getCurrentUser());

        if (!\in_array($kind, ['all', 'withdrawal', 'deposit', 'transfer'], true)) {
            throw new BadRequestHttpException('Invalid kind filter.');
        }

        $toEntry = static fn (array $row) => new DistributionEntryResponse(
            id: $row['id'],
            name: $row['name'],
            total: $row['total'],
        );

        return $this->json(new DistributionsResponse(
            categories: array_map($toEntry, $this->transactionRepository->sumByCategory($user, $kind, $from, $to)),
            sourceAccounts: array_map($toEntry, $this->transactionRepository->sumBySourceAccount($user, $kind, $from, $to)),
            destinationAccounts: array_map($toEntry, $this->transactionRepository->sumByDestinationAccount($user, $kind, $from, $to)),
        ));
    }

    #[Route('/date-range', name: 'api_transactions_date_range', methods: ['GET'])]
    public function dateRange(): JsonResponse
    {
        [$earliest, $latest] = $this->transactionRepository->findDateRange($this->getCurrentUser());

        return $this->json(new DateRangeResponse(
            earliest: $earliest?->format(\DateTimeInterface::ATOM),
            latest: $latest?->format(\DateTimeInterface::ATOM),
        ));
    }

    #[Route('/{id}', name: 'api_transactions_get', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function get(string $id): JsonResponse
    {
        return $this->json(TransactionResponse::fromEntity($this->findOwned($id)));
    }

    #[Route('', name: 'api_transactions_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] CreateTransactionRequest $request): JsonResponse
    {
        try {
            $transaction = $this->transactionService->create($this->getCurrentUser(), $request);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        return $this->json(TransactionResponse::fromEntity($transaction), 201);
    }

    #[Route('/{id}', name: 'api_transactions_update', methods: ['PUT', 'PATCH'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function update(string $id, #[MapRequestPayload] CreateTransactionRequest $request): JsonResponse
    {
        $transaction = $this->findOwned($id);

        try {
            $transaction = $this->transactionService->update($transaction, $request);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        return $this->json(TransactionResponse::fromEntity($transaction));
    }

    #[Route('/{id}/audit-log', name: 'api_transactions_audit_log', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function auditLog(string $id): JsonResponse
    {
        $transaction = $this->findOwned($id);
        $logs = $this->transactionAuditLogRepository->findByTransaction($transaction);

        return $this->json(array_map(
            static fn ($log) => TransactionAuditLogResponse::fromEntity($log),
            $logs
        ));
    }

    #[Route('/{id}', name: 'api_transactions_delete', methods: ['DELETE'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function delete(string $id): JsonResponse
    {
        $transaction = $this->findOwned($id);
        $this->transactionService->delete($transaction);

        return $this->json(null, 204);
    }

    private function findOwned(string $id): \App\Entity\Transaction
    {
        $transaction = $this->transactionRepository->findOneByUserAndId($this->getCurrentUser(), $id);

        if ($transaction === null) {
            throw ApiException::notFound(ErrorCode::TransactionNotFound);
        }

        return $transaction;
    }

    private function getCurrentUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw ApiException::notFound(ErrorCode::TransactionNotFound);
        }

        return $user;
    }
}
