<?php

namespace App\Transaction\Controller;

use App\Core\Controller\ResolvesCurrentUser;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Core\Pagination\PaginatedResponse;
use App\Core\Pagination\PaginationParams;
use App\Core\Period\UserDayRange;
use App\Core\Search\SearchQuery;
use App\Draft\DTO\DraftResponse;
use App\Draft\Service\DraftService;
use App\Entity\Account;
use App\Entity\Draft;
use App\Entity\Category;
use App\Entity\RecurringTransaction;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\AccountRepository;
use App\Repository\CategoryRepository;
use App\Repository\RecurringTransactionRepository;
use App\Repository\TagRepository;
use App\Repository\TransactionRepository;
use App\Transaction\DTO\BulkTransactionRequest;
use App\Transaction\DTO\CreateTransactionRequest;
use App\Transaction\DTO\DateRangeResponse;
use App\Transaction\DTO\DistributionEntryResponse;
use App\Transaction\DTO\DistributionsResponse;
use App\Transaction\DTO\TransactionAuditLogResponse;
use App\Transaction\DTO\TransactionResponse;
use App\Transaction\Service\TransactionListService;
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
    use ResolvesCurrentUser;

    public function __construct(
        private readonly TransactionRepository $transactionRepository,
        private readonly TransactionService $transactionService,
        private readonly TransactionListService $transactionList,
        private readonly AccountRepository $accountRepository,
        private readonly TransactionAuditLogRepository $transactionAuditLogRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly TagRepository $tagRepository,
        private readonly RecurringTransactionRepository $recurringRepository,
        private readonly DraftService $draftService,
    ) {
    }

    /**
     * `kind` = all | withdrawal | deposit | transfer | trade (investment
     * BUY/SELLs only) | cash (money transactions only, any kind); optional
     * `from`/`to`, `accountId` and `q`, a search (see SearchQuery).
     * `categoryId` (a category's id, or `none` for no category), `tagId` or `recurringId`
     * narrows a money list (cash, withdrawal, deposit, transfer) without a
     * search: a report's cell, a category's or a tag's page.
     */
    #[Route('', name: 'api_transactions_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        $filter = $this->parseListFilter($request, $user);
        $params = PaginationParams::fromRequest($request, $user);
        $isPlainKind = $filter['search'] === null && \in_array($filter['kind'], ['cash', 'withdrawal', 'deposit', 'transfer'], true);
        $category = $this->parseCategoryFilter($request, $user);
        $tag = $this->parseTagFilter($request, $user);
        $recurring = $this->parseRecurringFilter($request, $user);
        if (($category !== null || $tag !== null || $recurring !== null) && !$isPlainKind) {
            throw new BadRequestHttpException('A category or tag narrows a money list (cash, withdrawal, deposit, transfer) without a search.');
        }

        // Plain kinds without a search keep the ORM list; anything mixing the two
        // entities, or searching, goes through the merged SQL list.
        if ($isPlainKind) {
            [$transactions, $total] = $this->transactionRepository->findByUserPaginated($user, $filter['kind'], $filter['from'], $filter['to'], $params, $filter['account'], $category, $tag, $recurring);

            $items = array_map(
                static fn ($transaction) => TransactionResponse::fromEntity($transaction),
                $transactions
            );

            return $this->json(PaginatedResponse::create($items, $total, $params));
        }

        return $this->json($this->transactionList->page($user, $filter, $params));
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
            ...TransactionListService::halves($filter['kind']),
            search: $filter['search'],
        ));
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

    /** The `recurringId` filter: what a recurring transaction created, on its page. */
    private function parseRecurringFilter(Request $request, User $user): ?RecurringTransaction
    {
        $id = $request->query->get('recurringId');
        if ($id === null || $id === '') {
            return null;
        }

        return $this->recurringRepository->findOneByUserAndId($user, $id)
            ?? throw ApiException::notFound(ErrorCode::RecurringNotFound);
    }

    /** The `tagId` filter: a tag of the user, or null when not given. */
    private function parseTagFilter(Request $request, User $user): ?Tag
    {
        $tagId = $request->query->get('tagId');
        if ($tagId === null || $tagId === '') {
            return null;
        }

        return $this->tagRepository->findOneByUserAndId($user, $tagId)
            ?? throw ApiException::notFound(ErrorCode::TagNotFound);
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
        $user = $this->getCurrentUser();
        [$earliest, $latest] = $this->transactionRepository->findDateRange($user);

        // "All" runs up to today, so values are today's; later only for
        // transactions dated in the future.
        $today = (new \DateTimeImmutable('now', new \DateTimeZone($user->getTimezone())))->format('Y-m-d');

        return $this->json(new DateRangeResponse(
            earliest: $earliest?->format('Y-m-d'),
            latest: max($latest?->format('Y-m-d') ?? $today, $today),
        ));
    }

    /**
     * While typing a new transaction's description: past transactions to copy
     * accounts, category and tags from (TransactionRepository::suggestionsByDescription).
     */
    #[Route('/suggestions', name: 'api_transactions_suggestions', methods: ['GET'])]
    public function suggestions(Request $request): JsonResponse
    {
        $text = trim((string) $request->query->get('q', ''));
        if (mb_strlen($text) < 2) {
            return $this->json([]);
        }

        return $this->json(array_map(
            static fn ($transaction) => TransactionResponse::fromEntity($transaction),
            $this->transactionRepository->suggestionsByDescription($this->getCurrentUser(), $text),
        ));
    }

    #[Route('/{id}', name: 'api_transactions_get', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function get(string $id): JsonResponse
    {
        return $this->json(TransactionResponse::fromEntity($this->findOwned($id)));
    }

    #[Route('', name: 'api_transactions_create', methods: ['POST'])]
    /**
     * 201 with the new transaction; with `ifExists` and an identical one
     * there already, 200 with that one (`skip`, `annotate`) or 202 with a
     * draft for the user to decide (`review`).
     */
    public function create(#[MapRequestPayload] CreateTransactionRequest $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        if ($request->ifExists !== null && $request->ifExists !== 'create') {
            $outcome = $this->draftService->incomingTransaction($user, $request);
            if ($outcome instanceof Draft) {
                return $this->json(DraftResponse::fromEntity($outcome, $this->draftService->currency($outcome)), 202);
            }
            if ($outcome !== null) {
                return $this->json(TransactionResponse::fromEntity($outcome), 200);
            }
        }
        $transaction = $this->transactionService->create($user, $request);

        return $this->json(TransactionResponse::fromEntity($transaction), 201);
    }

    #[Route('/{id}', name: 'api_transactions_update', methods: ['PUT', 'PATCH'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function update(string $id, #[MapRequestPayload] CreateTransactionRequest $request): JsonResponse
    {
        $transaction = $this->findOwned($id);

        $transaction = $this->transactionService->update($transaction, $request);

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

    /** One change to several money transactions at once (BulkTransactionRequest): how many were changed. */
    #[Route('/bulk', name: 'api_transactions_bulk', methods: ['POST'])]
    public function bulk(#[MapRequestPayload] BulkTransactionRequest $request): JsonResponse
    {
        return $this->json(['count' => $this->transactionService->bulk($this->getCurrentUser(), $request)]);
    }

    /** "Undo" right after a delete (RestoreWindow): the transaction as it was. */
    #[Route('/{id}/restore', name: 'api_transactions_restore', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function restore(string $id): JsonResponse
    {
        return $this->json(TransactionResponse::fromEntity($this->transactionService->restore($this->getCurrentUser(), $id)));
    }

    private function findOwned(string $id): \App\Entity\Transaction
    {
        $transaction = $this->transactionRepository->findOneByUserAndId($this->getCurrentUser(), $id);

        if ($transaction === null) {
            throw ApiException::notFound(ErrorCode::TransactionNotFound);
        }

        return $transaction;
    }
}
