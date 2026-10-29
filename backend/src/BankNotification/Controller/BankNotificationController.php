<?php

namespace App\BankNotification\Controller;

use App\BankNotification\DTO\IncomingNotificationRequest;
use App\BankNotification\DTO\SourceRequest;
use App\BankNotification\Service\BankNotificationService;
use App\BankNotification\Service\SourceRemover;
use App\Core\Controller\ResolvesCurrentUser;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Entity\NotificationSource;
use App\Repository\NotificationSourceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/** The phone's notifications coming in, and the Sources page: each app, its account and how to read it. */
class BankNotificationController extends AbstractController
{
    use ResolvesCurrentUser;

    private const ID = ['id' => '[0-9a-fA-F-]{36}'];

    public function __construct(
        private readonly BankNotificationService $service,
        private readonly NotificationSourceRepository $sources,
        private readonly SourceRemover $remover,
    ) {
    }

    /** 201 with what it became: a draft (its id), ignored, or unread. */
    #[Route('/api/bank-notifications', name: 'api_bank_notifications_receive', methods: ['POST'])]
    public function receive(#[MapRequestPayload] IncomingNotificationRequest $request): JsonResponse
    {
        $notification = $this->service->receive($this->getCurrentUser(), $request);

        return $this->json([
            'id' => (string) $notification->getId(),
            'status' => $notification->getStatus(),
            'draftId' => $notification->getDraft()?->getId()->toRfc4122(),
        ], 201);
    }

    #[Route('/api/sources', name: 'api_sources_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(array_map(static fn (array $row) => [
            ...self::toArray($row['source']),
            'total' => $row['total'],
            'unread' => $row['unread'],
            'latest' => $row['latest'],
        ], $this->sources->findWithCounts($this->getCurrentUser())));
    }

    #[Route('/api/sources/{id}', name: 'api_sources_get', methods: ['GET'], requirements: self::ID)]
    public function get(string $id): JsonResponse
    {
        $source = $this->findOwned($id);

        return $this->json([...self::toArray($source), 'notifications' => $this->service->preview($source, $source->getRules())]);
    }

    #[Route('/api/sources/{id}', name: 'api_sources_update', methods: ['PUT'], requirements: self::ID)]
    public function update(string $id, #[MapRequestPayload] SourceRequest $request): JsonResponse
    {
        $source = $this->findOwned($id);
        $this->service->update($source, $request->accountId, $request->rules);

        return $this->json(self::toArray($source));
    }

    /** For good, with its notifications; the drafts they made still waiting are discarded. */
    #[Route('/api/sources/{id}', name: 'api_sources_delete', methods: ['DELETE'], requirements: self::ID)]
    public function delete(string $id): JsonResponse
    {
        $this->remover->delete($this->findOwned($id));

        return new JsonResponse(null, 204);
    }

    /** How these rules (not saved yet) would read the latest notifications. */
    #[Route('/api/sources/{id}/preview', name: 'api_sources_preview', methods: ['POST'], requirements: self::ID)]
    public function preview(string $id, #[MapRequestPayload] SourceRequest $request): JsonResponse
    {
        return $this->json($this->service->preview($this->findOwned($id), $request->rules));
    }

    /** The ones not understood yet, read again with the saved rules: those now understood become drafts. */
    #[Route('/api/sources/{id}/reread', name: 'api_sources_reread', methods: ['POST'], requirements: self::ID)]
    public function reread(string $id): JsonResponse
    {
        return $this->json(['drafts' => $this->service->reread($this->findOwned($id))]);
    }

    /** @return array<string, mixed> */
    private static function toArray(NotificationSource $source): array
    {
        return [
            'id' => (string) $source->getId(),
            'packageName' => $source->getPackageName(),
            'appName' => $source->getAppName(),
            'accountId' => $source->getAccount()?->getId()->toRfc4122(),
            'rules' => $source->getRules(),
        ];
    }

    private function findOwned(string $id): NotificationSource
    {
        return $this->sources->findOneByUserAndId($this->getCurrentUser(), $id)
            ?? throw ApiException::notFound(ErrorCode::SourceNotFound);
    }
}
