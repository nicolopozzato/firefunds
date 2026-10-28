<?php

namespace App\Notification\Controller;

use App\Core\Controller\ResolvesCurrentUser;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Entity\Notification;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** The bell: what is open for the user, read or not, and hiding one. */
#[Route('/api/notifications')]
class NotificationController extends AbstractController
{
    use ResolvesCurrentUser;

    private const ID = ['id' => '[0-9a-fA-F-]{36}'];

    public function __construct(
        private readonly NotificationRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'api_notifications_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(array_map(self::toArray(...), $this->repository->findShown($this->getCurrentUser())));
    }

    #[Route('/read', name: 'api_notifications_read_all', methods: ['POST'])]
    public function readAll(): JsonResponse
    {
        $now = $this->clock->now();
        foreach ($this->repository->findShown($this->getCurrentUser()) as $notification) {
            $notification->markRead($now);
        }
        $this->entityManager->flush();

        return new JsonResponse(null, 204);
    }

    #[Route('/{id}/read', name: 'api_notifications_read', methods: ['POST'], requirements: self::ID)]
    public function read(string $id): JsonResponse
    {
        $this->findOwned($id)->markRead($this->clock->now());
        $this->entityManager->flush();

        return new JsonResponse(null, 204);
    }

    /** Hidden until its cause is gone and comes back. */
    #[Route('/{id}/dismiss', name: 'api_notifications_dismiss', methods: ['POST'], requirements: self::ID)]
    public function dismiss(string $id): JsonResponse
    {
        $this->findOwned($id)->dismiss($this->clock->now());
        $this->entityManager->flush();

        return new JsonResponse(null, 204);
    }

    /** @return array<string, mixed> */
    private static function toArray(Notification $notification): array
    {
        return [
            'id' => (string) $notification->getId(),
            'type' => $notification->getType(),
            'params' => $notification->getParams(),
            'createdAt' => $notification->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $notification->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'read' => $notification->getReadAt() !== null,
        ];
    }

    private function findOwned(string $id): Notification
    {
        return $this->repository->findOneByUserAndId($this->getCurrentUser(), $id)
            ?? throw ApiException::notFound(ErrorCode::NotificationNotFound);
    }
}
