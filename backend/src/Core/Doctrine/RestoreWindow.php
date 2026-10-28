<?php

namespace App\Core\Doctrine;

use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Entity\SoftDeletable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Undo" right after a delete: a deleted row can be brought back for a few
 * minutes, the time the app offers the button. Not the trash (browsing and
 * restoring anything deleted, see the backlog): after that, or if what it
 * depends on was deleted meanwhile, it stays deleted.
 */
class RestoreWindow
{
    public const int MINUTES = 15;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Runs $find with deleted rows visible (SoftDeleteFilter off), so a deleted
     * row and what it points to (accounts, an instrument) can be read.
     *
     * @template T
     *
     * @param callable(): T $find
     *
     * @return T
     */
    public function withDeleted(callable $find): mixed
    {
        $filters = $this->entityManager->getFilters();
        $filters->disable('soft_delete');
        try {
            return $find();
        } finally {
            $filters->enable('soft_delete');
        }
    }

    /**
     * Refuses (409) unless $row was deleted within the window and nothing in
     * $dependsOn has been deleted since.
     *
     * @param list<SoftDeletable|null> $dependsOn
     */
    public function assertRestorable(?SoftDeletable $row, array $dependsOn = []): void
    {
        $deletedAt = $row?->getDeletedAt();
        $since = new \DateTimeImmutable(\sprintf('-%d minutes', self::MINUTES));
        $dependencyDeleted = array_filter($dependsOn, static fn (?SoftDeletable $entity) => $entity?->getDeletedAt() !== null) !== [];

        if ($deletedAt === null || $deletedAt < $since || $dependencyDeleted) {
            throw new ApiException(ErrorCode::RestoreUnavailable, [], Response::HTTP_CONFLICT);
        }
    }
}
