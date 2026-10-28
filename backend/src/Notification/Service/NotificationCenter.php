<?php

namespace App\Notification\Service;

use App\Core\Doctrine\PhysicalDelete;
use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Raises and resolves notifications by condition (`key`): raising one that
 * is open updates it, so a check run every few minutes never piles them up;
 * one the user dismissed stays hidden until its cause is gone and comes back.
 */
#[PhysicalDelete('Notifications are messages from the app, not user data: the resolved ones are deleted after 90 days, as the task run log.')]
class NotificationCenter
{
    private const KEEP_RESOLVED_DAYS = 90;

    public function __construct(
        private readonly NotificationRepository $repository,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @param array<string, string|int|float|null> $params */
    public function raise(User $user, string $type, string $key, array $params = []): Notification
    {
        $now = $this->clock->now();
        $notification = $this->repository->findOpen($user, $key);
        if ($notification === null) {
            $notification = new Notification($user, $type, $key, $params, $now);
            $this->entityManager->persist($notification);
        } else {
            $notification->update($params, $now);
        }

        return $notification;
    }

    /**
     * The same for every active administrator.
     *
     * @param array<string, string|int|float|null> $params
     */
    public function raiseForAdministrators(string $type, string $key, array $params = []): void
    {
        foreach ($this->administrators() as $admin) {
            $this->raise($admin, $type, $key, $params);
        }
    }

    /** The cause is gone (or dealt with): for whoever it was raised, `key` exactly or every key starting with it. */
    public function resolve(string $key, bool $prefix = false): void
    {
        $now = $this->clock->now();
        foreach ($this->repository->findOpenByKeyPrefix($key) as $notification) {
            if ($prefix || $notification->getKey() === $key) {
                $notification->resolve($now);
            }
        }
    }

    /** Resolved ones older than 90 days: what the daily cleanup deletes. */
    public function prune(): int
    {
        return $this->entityManager->createQuery('DELETE FROM '.Notification::class.' n WHERE n.resolvedAt < :before')
            ->setParameter('before', $this->clock->now()->modify('-'.self::KEEP_RESOLVED_DAYS.' days'))
            ->execute();
    }

    /** @return list<User> */
    private function administrators(): array
    {
        return array_values(array_filter(
            $this->users->findBy(['disabledAt' => null]),
            static fn (User $user) => $user->isAdmin(),
        ));
    }
}
