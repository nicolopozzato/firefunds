<?php

namespace App\Notification\Push;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Which notifications go to the devices, and when: server problems right
 * away (administrators who want them); reminders once a day, together, from
 * the user's summary time where they are. Each notification is pushed at
 * most once; one the user doesn't want pushed is marked so too.
 */
class PushDelivery
{
    public const SYSTEM_TYPES = ['task_failing', 'backup_overdue', 'prices_stale', 'rates_stale'];

    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly PushSender $sender,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return int notifications sent */
    public function deliver(): int
    {
        $now = $this->clock->now();
        $sent = 0;
        $reminders = [];
        foreach ($this->notifications->findToPush() as $notification) {
            $user = $notification->getUser();
            if (\in_array($notification->getType(), self::SYSTEM_TYPES, true)) {
                if ($user->wantsPushSystem()) {
                    $this->sender->send($user, $this->message($notification, $user));
                    ++$sent;
                }
                $notification->markPushed($now);
            } elseif (!$user->wantsPushReminders()) {
                $notification->markPushed($now);
            } else {
                $reminders[$user->getId()->toRfc4122()][] = $notification;
            }
        }

        foreach ($reminders as $list) {
            $user = $list[0]->getUser();
            $local = $now->setTimezone(new \DateTimeZone($user->getTimezone()));
            $today = new \DateTimeImmutable($local->format('Y-m-d'));
            if ($local->format('H:i') < $user->getPushSummaryTime() || $user->getPushSummarySentOn() == $today) {
                continue;
            }
            $this->sender->send($user, $this->summary($list, $user));
            $user->setPushSummarySentOn($today);
            foreach ($list as $notification) {
                $notification->markPushed($now);
            }
            ++$sent;
        }
        $this->entityManager->flush();

        return $sent;
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
