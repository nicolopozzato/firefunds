<?php

namespace App\BankNotification\Service;

use App\Core\Doctrine\PhysicalDelete;
use App\Draft\Service\DraftService;
use App\Entity\BankNotification;
use App\Entity\NotificationSource;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Removes a source the user turned on by mistake (a chat app, a test): the
 * source and its notifications go for good, the drafts they made still
 * waiting are discarded. Drafts already recorded keep their transactions.
 */
#[PhysicalDelete('A source\'s notifications are copies of what the phone showed, not the user\'s data: removed with their source on the user\'s request.')]
class SourceRemover
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DraftService $drafts,
    ) {
    }

    public function delete(NotificationSource $source): void
    {
        $notifications = $this->entityManager->getRepository(BankNotification::class)->findBy(['source' => $source]);
        foreach ($notifications as $notification) {
            $draft = $notification->getDraft();
            if ($draft !== null && $draft->isPending()) {
                $this->drafts->discard($draft);
            }
            $this->entityManager->remove($notification);
        }
        $this->entityManager->remove($source);
        $this->entityManager->flush();
    }
}
