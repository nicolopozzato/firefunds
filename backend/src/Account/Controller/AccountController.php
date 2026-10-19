<?php

namespace App\Account\Controller;

use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Account\DTO\AccountDistributionsResponse;
use App\Account\DTO\AccountListQuery;
use App\Account\DTO\AccountResponse;
use App\Account\DTO\AccountValueHistoryPointResponse;
use App\Account\DTO\BalancePointResponse;
use App\Account\DTO\CreateAccountRequest;
use App\Account\DTO\MoveAccountRequest;
use App\Account\DTO\UpdateAccountRequest;
use App\Account\DTO\DeleteAccountQuery;
use App\Account\Service\AccountDeletionService;
use App\Account\Service\AccountService;
use App\Core\Pagination\PaginatedResponse;
use App\Core\Pagination\PaginationParams;
use App\Core\Period\UserDayRange;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\User;
use App\Investment\Service\OpenPositionService;
use App\Repository\AccountRepository;
use App\Repository\InvestmentTransactionRepository;
use App\Repository\TransactionRepository;
use App\Transaction\DTO\DistributionEntryResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/accounts')]
class AccountController extends AbstractController
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly AccountService $accountService,
        private readonly AccountDeletionService $accountDeletionService,
        private readonly TransactionRepository $transactionRepository,
        private readonly InvestmentTransactionRepository $investmentTransactionRepository,
        private readonly OpenPositionService $openPositionService,
    ) {
    }

    #[Route('/{type}', name: 'api_accounts_list', methods: ['GET'], requirements: ['type' => 'asset|expense|revenue'])]
    public function list(string $type, Request $request, #[MapQueryString] AccountListQuery $filter = new AccountListQuery()): JsonResponse
    {
        $user = $this->getCurrentUser();
        $params = PaginationParams::fromRequest($request, $user);

        [$accounts, $total] = $this->accountRepository->findByUserAndTypePaginated(
            $user,
            AccountType::from($type),
            $params,
            $filter->isActive(),
            $filter->assetKind(),
        );

        $items = $this->toResponseBatch($accounts);

        return $this->json(PaginatedResponse::create($items, $total, $params));
    }

    #[Route('/{id}', name: 'api_accounts_get', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function get(string $id): JsonResponse
    {
        return $this->json($this->toResponse($this->findOwnedAccount($id)));
    }

    #[Route('/{id}/balance-history', name: 'api_accounts_balance_history', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function balanceHistory(string $id, Request $request): JsonResponse
    {
        $account = $this->findOwnedAccount($id);
        [$from, $to] = $this->parseDateRange($request);

        $points = $this->accountRepository->getDailyBalanceHistory($account, $from, $to);

        return $this->json(array_map(
            static fn (array $point) => new BalancePointResponse($point['date'], $point['balance']),
            $points
        ));
    }

    #[Route('/{id}/value-history', name: 'api_accounts_value_history', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function valueHistory(string $id, Request $request): JsonResponse
    {
        $account = $this->findOwnedAccount($id);
        [$from, $to] = $this->parseDateRange($request);

        $availableByDate = [];
        foreach ($this->accountRepository->getDailyBalanceHistory($account, $from, $to) as $point) {
            $availableByDate[$point['date']] = $point['balance'];
        }

        $investedByDate = [];
        if ($account->getType() === AccountType::Asset && $account->getAssetKind() === AssetAccountKind::Investment) {
            foreach ($this->investmentTransactionRepository->getDailyInvestedValueHistory($account, $from, $to) as $point) {
                $investedByDate[$point['date']] = $point['value'];
            }
        }

        $points = [];
        foreach ($availableByDate as $date => $available) {
            $invested = $investedByDate[$date] ?? '0.0000';
            $points[] = new AccountValueHistoryPointResponse(
                date: $date,
                available: $available,
                invested: $invested,
                total: bcadd($available, $invested, 4),
            );
        }

        return $this->json($points);
    }

    #[Route('/{id}/distributions', name: 'api_accounts_distributions', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function distributions(string $id, Request $request): JsonResponse
    {
        $account = $this->findOwnedAccount($id);
        // Transactions in the period: the user's calendar days.
        [$from, $to] = UserDayRange::fromRequest($request, $this->getCurrentUser());

        $toEntry = static fn (array $row) => new DistributionEntryResponse(
            id: $row['id'],
            name: $row['name'],
            total: $row['total'],
        );

        return $this->json(new AccountDistributionsResponse(
            expenses: array_map($toEntry, $this->transactionRepository->sumByCategoryForAccountExpenses($account, $from, $to)),
            income: array_map($toEntry, $this->transactionRepository->sumByCategoryForAccountIncome($account, $from, $to)),
        ));
    }

    /**
     * Open positions on an investment account, each enriched with live market
     * data and its own daily value/cost-basis history over the given range —
     * the data behind the account detail page's "Open positions" section. Only
     * this account's own positions: no cross-broker aggregation (that's
     * Investments -> Overview, PortfolioController).
     */
    #[Route('/{id}/positions', name: 'api_accounts_positions', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function positions(string $id, Request $request): JsonResponse
    {
        $account = $this->findOwnedAccount($id);
        [$from, $to] = $this->parseDateRange($request);

        return $this->json($this->openPositionService->forAccount($account, $from, $to));
    }

    #[Route('/{type}', name: 'api_accounts_create', methods: ['POST'], requirements: ['type' => 'asset|expense|revenue'])]
    public function create(string $type, #[MapRequestPayload] CreateAccountRequest $request): JsonResponse
    {
        if ($request->type !== $type) {
            throw new BadRequestHttpException('Route type and payload type must match.');
        }

        try {
            $account = $this->accountService->create($this->getCurrentUser(), $request);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        return $this->json($this->toResponse($account), 201);
    }

    #[Route('/{id}', name: 'api_accounts_update', methods: ['PUT', 'PATCH'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function update(string $id, #[MapRequestPayload] UpdateAccountRequest $request): JsonResponse
    {
        $account = $this->findOwnedAccount($id);

        try {
            $account = $this->accountService->update($account, $request);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        return $this->json($this->toResponse($account));
    }

    /**
     * Puts the account right after or right before another one of its type
     * (dragged on the accounts list); every list of accounts follows this order.
     */
    #[Route('/{id}/position', name: 'api_accounts_move', methods: ['PUT'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function move(string $id, #[MapRequestPayload] MoveAccountRequest $request): JsonResponse
    {
        try {
            $this->accountService->move($this->findOwnedAccount($id), $request->afterId, $request->beforeId);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        return $this->json(null, 204);
    }

    /**
     * What deleting this account would affect, for the confirmation dialog.
     */
    #[Route('/{id}/deletion-preview', name: 'api_accounts_deletion_preview', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function deletionPreview(string $id): JsonResponse
    {
        return $this->json($this->accountDeletionService->preview($this->findOwnedAccount($id)));
    }

    /**
     * Marks the account as deleted. If it has transactions, the caller must
     * say what happens to them: `?transferTo=<account id>` or
     * `?deleteTransactions=1`; otherwise 409.
     */
    #[Route('/{id}', name: 'api_accounts_delete', methods: ['DELETE'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function delete(string $id, #[MapQueryString] DeleteAccountQuery $query = new DeleteAccountQuery()): JsonResponse
    {
        $account = $this->findOwnedAccount($id);

        try {
            $this->accountDeletionService->delete($account, $query->transferTo, $query->deleteTransactions);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        return $this->json(null, 204);
    }

    private function toResponse(\App\Entity\Account $account): AccountResponse
    {
        return $this->toResponseBatch([$account])[0];
    }

    /**
     * @param \App\Entity\Account[] $accounts
     * @return AccountResponse[]
     */
    private function toResponseBatch(array $accounts): array
    {
        $balances = $this->accountRepository->getCurrentBalances($accounts);
        $lastActivityDates = $this->accountRepository->getLastActivityDates($accounts);
        $investedSummaries = $this->openPositionService->investedSummaries($accounts);

        return array_map(function (\App\Entity\Account $account) use ($balances, $lastActivityDates, $investedSummaries) {
            $id = (string) $account->getId();
            $invested = $investedSummaries[$id] ?? null;

            return AccountResponse::fromEntity(
                $account,
                $balances[$id] ?? '0.0000',
                $lastActivityDates[$id] ?? null,
                $invested['investedValue'] ?? null,
                $invested['gainAmount'] ?? null,
                $invested['gainPercent'] ?? null,
                $invested['estimatedTax'] ?? null,
            );
        }, $accounts);
    }

    private function findOwnedAccount(string $id): \App\Entity\Account
    {
        $account = $this->accountRepository->findOneByUserAndId($this->getCurrentUser(), $id);

        if ($account === null) {
            throw ApiException::notFound(ErrorCode::AccountNotFound);
        }

        return $account;
    }

    private function getCurrentUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw ApiException::notFound(ErrorCode::AccountNotFound);
        }

        return $user;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function parseDateRange(Request $request): array
    {
        $from = $request->query->get('from');
        $to = $request->query->get('to');

        try {
            return [
                $from !== null ? new \DateTimeImmutable($from) : new \DateTimeImmutable('first day of this month'),
                $to !== null ? new \DateTimeImmutable($to) : new \DateTimeImmutable('last day of this month'),
            ];
        } catch (\Exception) {
            throw new ApiException(ErrorCode::InvalidDateRange);
        }
    }
}
