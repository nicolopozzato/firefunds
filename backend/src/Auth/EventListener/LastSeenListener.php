<?php

namespace App\Auth\EventListener;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Records when a user last used the app (User::$lastSeenAt), shown to the
 * administrator. Every authenticated request is a "login" to the stateless
 * firewalls (password, refresh token or JWT), so this runs on each of them:
 * it writes only when the last record is older than PRECISION, a single
 * UPDATE that leaves the request's own unit of work alone.
 */
#[AsEventListener]
final readonly class LastSeenListener
{
    public const PRECISION = '15 minutes';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $now = new \DateTimeImmutable();
        $lastSeen = $user->getLastSeenAt();
        if (null !== $lastSeen && $lastSeen > $now->modify('-'.self::PRECISION)) {
            return;
        }

        $this->entityManager->createQuery('UPDATE App\Entity\User u SET u.lastSeenAt = :now WHERE u.id = :id')
            ->setParameter('now', $now)
            ->setParameter('id', $user->getId(), 'uuid')
            ->execute();
        // The entity in memory follows, so later checks in this request see it.
        $this->entityManager->getUnitOfWork()->setOriginalEntityProperty(spl_object_id($user), 'lastSeenAt', $now);
        $user->setLastSeenAt($now);
    }
}
