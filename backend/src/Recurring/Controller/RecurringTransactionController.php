<?php

namespace App\Recurring\Controller;

use App\Core\Controller\ResolvesCurrentUser;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Entity\RecurringTransaction;
use App\Entity\RecurringTransactionAuditLog;
use App\Recurring\DTO\ConfirmOccurrenceRequest;
use App\Recurring\DTO\RecurringTransactionRequest;
use App\Recurring\Service\RecurringTransactionService;
use App\Repository\RecurringTransactionAuditLogRepository;
use App\Repository\RecurringTransactionRepository;
use App\Transaction\DTO\TransactionResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/recurring-transactions')]
class RecurringTransactionController extends AbstractController
{
    use ResolvesCurrentUser;

    private const ID = ['id' => '[0-9a-fA-F-]{36}'];

    public function __construct(
        private readonly RecurringTransactionService $service,
        private readonly RecurringTransactionRepository $repository,
        private readonly RecurringTransactionAuditLogRepository $auditLogRepository,
    ) {
    }

    #[Route('', name: 'api_recurring_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json($this->service->list($this->getCurrentUser()));
    }

    /** What falls over the next `days` (30 unless said, at most a year). */
    #[Route('/upcoming', name: 'api_recurring_upcoming', methods: ['GET'])]
    public function upcoming(Request $request): JsonResponse
    {
        $days = max(1, min(366, $request->query->getInt('days', 30)));

        return $this->json($this->service->upcoming($this->getCurrentUser(), $days));
    }

    /** Due and waiting for the user: the first of each recurrence that asks to be confirmed. */
    #[Route('/pending', name: 'api_recurring_pending', methods: ['GET'])]
    public function pending(): JsonResponse
    {
        return $this->json($this->service->pending($this->getCurrentUser()));
    }

    #[Route('/{id}', name: 'api_recurring_get', methods: ['GET'], requirements: self::ID)]
    public function get(string $id): JsonResponse
    {
        return $this->json($this->service->toResponse($this->findOwned($id)));
    }

    #[Route('', name: 'api_recurring_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] RecurringTransactionRequest $request): JsonResponse
    {
        return $this->json($this->service->toResponse($this->service->create($this->getCurrentUser(), $request)), 201);
    }

    #[Route('/{id}', name: 'api_recurring_update', methods: ['PUT'], requirements: self::ID)]
    public function update(string $id, #[MapRequestPayload] RecurringTransactionRequest $request): JsonResponse
    {
        return $this->json($this->service->toResponse($this->service->update($this->findOwned($id), $request)));
    }

    #[Route('/{id}', name: 'api_recurring_delete', methods: ['DELETE'], requirements: self::ID)]
    public function delete(string $id): JsonResponse
    {
        $this->service->delete($this->findOwned($id));

        return new JsonResponse(null, 204);
    }

    /** Its history, newest first. */
    #[Route('/{id}/audit-log', name: 'api_recurring_audit_log', methods: ['GET'], requirements: self::ID)]
    public function auditLog(string $id): JsonResponse
    {
        return $this->json(array_map(
            static fn (RecurringTransactionAuditLog $log) => [
                'id' => (string) $log->getId(),
                'action' => $log->getAction(),
                'changes' => $log->getChanges(),
                'createdAt' => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ],
            $this->auditLogRepository->findByRecurring($this->findOwned($id)),
        ));
    }

    #[Route('/{id}/pause', name: 'api_recurring_pause', methods: ['POST'], requirements: self::ID)]
    public function pause(string $id): JsonResponse
    {
        $recurring = $this->findOwned($id);
        $this->service->setPaused($recurring, true);

        return $this->json($this->service->toResponse($recurring));
    }

    /** Back on from today: what fell while paused is not created. */
    #[Route('/{id}/resume', name: 'api_recurring_resume', methods: ['POST'], requirements: self::ID)]
    public function resume(string $id): JsonResponse
    {
        $recurring = $this->findOwned($id);
        $this->service->setPaused($recurring, false);

        return $this->json($this->service->toResponse($recurring));
    }

    #[Route('/{id}/confirm', name: 'api_recurring_confirm', methods: ['POST'], requirements: self::ID)]
    public function confirm(string $id, #[MapRequestPayload] ConfirmOccurrenceRequest $request = new ConfirmOccurrenceRequest()): JsonResponse
    {
        return $this->json(TransactionResponse::fromEntity($this->service->confirm($this->findOwned($id), $request)), 201);
    }

    /** Transactions that could be the first waiting occurrence, to link by hand. */
    #[Route('/{id}/linkable', name: 'api_recurring_linkable', methods: ['GET'], requirements: self::ID)]
    public function linkable(string $id): JsonResponse
    {
        return $this->json(array_map(TransactionResponse::fromEntity(...), $this->service->linkable($this->findOwned($id))));
    }

    /** "I entered it already": the transaction becomes the first waiting occurrence. */
    #[Route('/{id}/link', name: 'api_recurring_link', methods: ['POST'], requirements: self::ID)]
    public function link(string $id, Request $request): JsonResponse
    {
        $transactionId = (string) ($request->toArray()['transactionId'] ?? '');

        return $this->json(TransactionResponse::fromEntity($this->service->link($this->findOwned($id), $transactionId)));
    }

    #[Route('/{id}/skip', name: 'api_recurring_skip', methods: ['POST'], requirements: self::ID)]
    public function skip(string $id): JsonResponse
    {
        $recurring = $this->findOwned($id);
        $this->service->skip($recurring);

        return $this->json($this->service->toResponse($recurring));
    }

    private function findOwned(string $id): RecurringTransaction
    {
        return $this->repository->findOneByUserAndId($this->getCurrentUser(), $id)
            ?? throw ApiException::notFound(ErrorCode::RecurringNotFound);
    }
}
