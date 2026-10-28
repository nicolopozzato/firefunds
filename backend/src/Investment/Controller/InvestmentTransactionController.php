<?php

namespace App\Investment\Controller;

use App\Core\Controller\ResolvesCurrentUser;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Core\Pagination\PaginatedResponse;
use App\Core\Pagination\PaginationParams;
use App\Core\Period\UserDayRange;
use App\Entity\InvestmentTransaction;
use App\Investment\DTO\CreateInvestmentTransactionRequest;
use App\Investment\DTO\InvestmentDistributionsResponse;
use App\Investment\DTO\InvestmentTransactionAuditLogResponse;
use App\Investment\DTO\InvestmentTransactionResponse;
use App\Investment\Service\InvestmentTransactionService;
use App\Repository\AccountRepository;
use App\Repository\InvestmentInstrumentRepository;
use App\Repository\InvestmentTransactionAuditLogRepository;
use App\Repository\InvestmentTransactionRepository;
use App\Transaction\DTO\DistributionEntryResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/investment-transactions')]
class InvestmentTransactionController extends AbstractController
{
    use ResolvesCurrentUser;

    public function __construct(
        private readonly InvestmentTransactionRepository $investmentTransactionRepository,
        private readonly InvestmentTransactionService $investmentTransactionService,
        private readonly AccountRepository $accountRepository,
        private readonly InvestmentInstrumentRepository $instrumentRepository,
        private readonly InvestmentTransactionAuditLogRepository $investmentTransactionAuditLogRepository,
    ) {
    }

    #[Route('', name: 'api_investment_transactions_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        [$from, $to] = UserDayRange::fromRequest($request, $user);
        $params = PaginationParams::fromRequest($request, $user);

        $account = null;
        if (($accountId = $request->query->get('accountId')) !== null) {
            $account = $this->accountRepository->findOneByUserAndId($user, $accountId);

            if ($account === null) {
                throw ApiException::notFound(ErrorCode::AccountNotFound);
            }
        }

        $instrument = null;
        if (($instrumentId = $request->query->get('instrumentId')) !== null) {
            $instrument = $this->instrumentRepository->findOneByUserAndId($user, $instrumentId);

            if ($instrument === null) {
                throw ApiException::notFound(ErrorCode::InstrumentNotFound);
            }
        }

        [$transactions, $total] = $this->investmentTransactionRepository->findByUserPaginated($user, $params, $account, $instrument, $from, $to);

        $items = array_map(
            static fn ($transaction) => InvestmentTransactionResponse::fromEntity($transaction),
            $transactions
        );

        return $this->json(PaginatedResponse::create($items, $total, $params));
    }

    /** The pies above the list: the trades' totals per instrument type, instrument and account in the period. */
    #[Route('/distributions', name: 'api_investment_transactions_distributions', methods: ['GET'])]
    public function distributions(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        [$from, $to] = UserDayRange::fromRequest($request, $user);

        $entries = fn (string $groupBy) => array_map(
            static fn (array $row) => new DistributionEntryResponse(id: $row['id'], name: $row['name'], total: $row['total']),
            $this->investmentTransactionRepository->sumTotalsBy($groupBy, $user, $from, $to),
        );

        return $this->json(new InvestmentDistributionsResponse(
            instrumentTypes: $entries('instrumentType'),
            instruments: $entries('instrument'),
            accounts: $entries('account'),
        ));
    }

    /**
     * Past trades whose description contains the text, the latest of each: the
     * trade form copies their account, instrument and costs from the one picked.
     */
    #[Route('/suggestions', name: 'api_investment_transactions_suggestions', methods: ['GET'])]
    public function suggestions(Request $request): JsonResponse
    {
        $text = trim((string) $request->query->get('q', ''));
        if (mb_strlen($text) < 2) {
            return $this->json([]);
        }

        return $this->json(array_map(
            static fn ($trade) => InvestmentTransactionResponse::fromEntity($trade),
            $this->investmentTransactionRepository->suggestionsByDescription($this->getCurrentUser(), $text),
        ));
    }

    #[Route('/{id}', name: 'api_investment_transactions_get', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function get(string $id): JsonResponse
    {
        return $this->json(InvestmentTransactionResponse::fromEntity($this->findOwned($id)));
    }

    #[Route('', name: 'api_investment_transactions_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] CreateInvestmentTransactionRequest $request): JsonResponse
    {
        $saved = $this->investmentTransactionService->create($this->getCurrentUser(), $request);

        return $this->json(InvestmentTransactionResponse::fromEntity($saved->transaction, $saved->pricesIncomplete), 201);
    }

    #[Route('/{id}', name: 'api_investment_transactions_update', methods: ['PUT', 'PATCH'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function update(string $id, #[MapRequestPayload] CreateInvestmentTransactionRequest $request): JsonResponse
    {
        $transaction = $this->findOwned($id);

        $saved = $this->investmentTransactionService->update($transaction, $request);

        return $this->json(InvestmentTransactionResponse::fromEntity($saved->transaction, $saved->pricesIncomplete));
    }

    #[Route('/{id}/audit-log', name: 'api_investment_transactions_audit_log', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function auditLog(string $id): JsonResponse
    {
        $transaction = $this->findOwned($id);
        $logs = $this->investmentTransactionAuditLogRepository->findByInvestmentTransaction($transaction);

        return $this->json(array_map(
            static fn ($log) => InvestmentTransactionAuditLogResponse::fromEntity($log),
            $logs
        ));
    }

    #[Route('/{id}', name: 'api_investment_transactions_delete', methods: ['DELETE'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function delete(string $id): JsonResponse
    {
        $transaction = $this->findOwned($id);
        $this->investmentTransactionService->delete($transaction);

        return $this->json(null, 204);
    }

    /** "Undo" right after a delete (RestoreWindow): the trade as it was. */
    #[Route('/{id}/restore', name: 'api_investment_transactions_restore', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function restore(string $id): JsonResponse
    {
        return $this->json(InvestmentTransactionResponse::fromEntity($this->investmentTransactionService->restore($this->getCurrentUser(), $id)));
    }

    private function findOwned(string $id): InvestmentTransaction
    {
        $transaction = $this->investmentTransactionRepository->findOneByUserAndId($this->getCurrentUser(), $id);

        if ($transaction === null) {
            throw ApiException::notFound(ErrorCode::InvestmentTransactionNotFound);
        }

        return $transaction;
    }
}
