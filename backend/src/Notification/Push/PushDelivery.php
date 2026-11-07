<?php

namespace App\Notification\Push;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Which notifications go to the devices, and when: server problems right
 * away (administrators who want them), drafts as they arrive; reminders once
 * a day, together, from
 * the user's summary time where they are. Each notification is pushed at
 * most once; one the user doesn't want pushed is marked so too.
 */
class PushDelivery
{
    public const SYSTEM_TYPES = ['task_failing', 'backup_overdue', 'prices_stale', 'rates_stale'];
    /** A draft is pushed the moment it arrives: that is when the user remembers what it was. */
    public const DRAFT_TYPES = ['draft_new', 'draft_recorded'];
    private const PAGE = 200;

    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly PushSender $sender,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * A page at a time: what a page pushed is saved and let go before the
     * next, so a pile of notifications (one user's thousands of drafts) costs
     * time, never all the memory at once, and a failure keeps what was done.
     *
     * @return int notifications sent
     */
    public function deliver(): int
    {
        $now = $this->clock->now();
        $sent = 0;
        $reminders = [];
        $after = null;
        do {
            $page = $this->notifications->findToPush(self::PAGE, $after);
            foreach ($page as $notification) {
                $user = $notification->getUser();
                if (\in_array($notification->getType(), self::SYSTEM_TYPES, true)) {
                    if ($user->wantsPushSystem()) {
                        $sent += $this->send($user, $this->message($notification, $user));
                    }
                    $notification->markPushed($now);
                } elseif (\in_array($notification->getType(), self::DRAFT_TYPES, true)) {
                    if ($user->wantsPushDrafts()) {
                        $sent += $this->send($user, $this->message($notification, $user));
                    }
                    $notification->markPushed($now);
                } elseif (!$user->wantsPushReminders()) {
                    $notification->markPushed($now);
                } else {
                    $reminders[$user->getId()->toRfc4122()][] = $notification;
                }
            }
            $this->entityManager->flush();
            foreach ($page as $notification) {
                if ($notification->isPushed()) {
                    $this->entityManager->detach($notification);
                }
            }
            $after = $page === [] ? null : end($page)->getId();
        } while (\count($page) === self::PAGE);

        foreach ($reminders as $list) {
            $user = $list[0]->getUser();
            $local = $now->setTimezone(new \DateTimeZone($user->getTimezone()));
            $today = new \DateTimeImmutable($local->format('Y-m-d'));
            if ($local->format('H:i') < $user->getPushSummaryTime() || $user->getPushSummarySentOn() == $today) {
                continue;
            }
            $sent += $this->send($user, $this->summary($list, $user));
            $user->setPushSummarySentOn($today);
            foreach ($list as $notification) {
                $notification->markPushed($now);
            }
        }
        $this->entityManager->flush();

        return $sent;
    }

    /**
     * One user's failure stays theirs: the others' notifications still go,
     * and theirs is marked pushed all the same, so it never blocks the queue.
     *
     * @param array{title: string, body: string, url: string, tag: string} $message
     */
    private function send(User $user, array $message): int
    {
        try {
            $this->sender->send($user, $message);

            return 1;
        } catch (\Throwable $e) {
            $this->logger->error('Push to user {user} failed: {error}', ['user' => $user->getId()->toRfc4122(), 'error' => $e->getMessage()]);

            return 0;
        }
    }

    /** @return array{title: string, body: string, url: string, tag: string} */
    private function message(Notification $notification, User $user): array
    {
        return [
            ...PushTexts::of($notification->getType(), $notification->getParams(), $user->getLocale()),
            'url' => PushTexts::url($notification),
            'tag' => $notification->getKey(),
        ];
    }

    /**
     * One reminder as it is; several as one line each, the page that lists them.
     *
     * @param list<Notification> $list
     * @return array{title: string, body: string, url: string, tag: string}
     */
    private function summary(array $list, User $user): array
    {
        if (\count($list) === 1) {
            return $this->message($list[0], $user);
        }

        return [
            ...PushTexts::of('recurring_summary', [
                'count' => \count($list),
                'list' => implode(', ', array_map(static fn (Notification $n) => (string) ($n->getParams()['description'] ?? ''), $list)),
            ], $user->getLocale()),
            'url' => '/transactions/recurring',
            'tag' => 'recurring-summary',
        ];
    }
}
