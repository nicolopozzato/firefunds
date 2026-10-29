<?php

namespace App\BankNotification\Service;

use App\BankNotification\DTO\IncomingNotificationRequest;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Draft\DTO\CreateDraftRequest;
use App\Draft\Service\DraftService;
use App\Entity\AccountType;
use App\Entity\BankNotification;
use App\Entity\NotificationSource;
use App\Entity\User;
use App\Repository\AccountRepository;
use App\Repository\BankNotificationRepository;
use App\Repository\NotificationSourceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Notifications the phone sends: kept as they came, under their app (a
 * source, made with its first one), and read (NotificationReading): a
 * payment becomes a draft on the source's account (checked against what is
 * there, as every draft is), an ignored kind is set aside, anything else
 * waits for a rule on the Sources page.
 */
class BankNotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationSourceRepository $sources,
        private readonly BankNotificationRepository $notifications,
        private readonly AccountRepository $accounts,
        private readonly DraftService $drafts,
    ) {
    }

    public function receive(User $user, IncomingNotificationRequest $request): BankNotification
    {
        $known = $this->notifications->findOneByDeviceKey($user, $request->key);
        if ($known !== null) {
            return $known;
        }

        $source = $this->sources->findOneByUserAndPackage($user, $request->package);
        if ($source === null) {
            $source = new NotificationSource($user, $request->package, $request->appName);
            $this->entityManager->persist($source);
        } else {
            $source->setAppName($request->appName);
        }

        $postedAt = new \DateTimeImmutable($request->postedAt);
        $notification = new BankNotification($user, $source, $request->title, $request->text, new \DateTimeImmutable($postedAt->format('Y-m-d H:i:s')), $request->timezone, $request->key);
        $this->entityManager->persist($notification);
        $this->read($notification);
        $this->entityManager->flush();

        return $notification;
    }

    /** The source's notifications not understood yet, read again with its rules as they are now. @return int how many became drafts */
    public function reread(NotificationSource $source): int
    {
        $drafts = 0;
        foreach ($this->notifications->findUnread($source) as $notification) {
            $this->read($notification);
            if ($notification->getStatus() === BankNotification::STATUS_DRAFT) {
                ++$drafts;
            }
        }
        $this->entityManager->flush();

        return $drafts;
    }

    /**
     * How these rules would read the source's latest notifications: to try a
     * rule before saving it. With the draft each became, as it stands now:
     * still to review, recorded (its first transaction), matched or discarded.
     *
     * @param list<array{kind: string, pattern: string}> $rules
     * @return list<array{id: string, title: string, text: string, postedAt: string, status: string, draft: ?array{id: string, status: string, transactionId: ?string}, reading: ?array<string, mixed>}>
     */
    public function preview(NotificationSource $source, array $rules): array
    {
        return array_map(static fn (BankNotification $notification) => [
            'id' => (string) $notification->getId(),
            'title' => $notification->getTitle(),
            'text' => $notification->getText(),
            'postedAt' => $notification->getPostedAt()->format('Y-m-d\TH:i:s'),
            'status' => $notification->getStatus(),
            'draft' => $notification->getDraft() === null ? null : [
                'id' => $notification->getDraft()->getId()->toRfc4122(),
                'status' => $notification->getDraft()->getStatus(),
                'transactionId' => $notification->getDraft()->getTransactionIds()[0] ?? null,
            ],
            'reading' => NotificationReading::read($rules, $notification->getFullText()),
        ], $this->notifications->findLatest($source));
    }

    /** @param list<array{kind: string, pattern: string}> $rules */
    public function update(NotificationSource $source, ?string $accountId, array $rules): void
    {
        $account = null;
        if ($accountId !== null) {
            $account = $this->accounts->findOneByUserAndId($source->getUser(), $accountId);
            if ($account === null || $account->getType() !== AccountType::Asset) {
                throw ApiException::notFound(ErrorCode::AccountNotFound);
            }
        }
        $source->setAccount($account);
        $source->setRules(array_values(array_map(static fn (array $rule) => ['kind' => $rule['kind'], 'pattern' => trim($rule['pattern'])], $rules)));
        $this->entityManager->flush();
    }

    private function read(BankNotification $notification): void
    {
        $source = $notification->getSource();
        $reading = NotificationReading::read($source->getRules(), $notification->getFullText());
        if ($reading === null) {
            return;
        }
        if ($reading['kind'] === 'ignore') {
            $notification->ignore();

            return;
        }

        $out = $reading['kind'] === 'out';
        $account = $source->getAccount()?->getId()->toRfc4122();
        $draft = $this->drafts->create($notification->getUser(), new CreateDraftRequest(
            amount: (string) $reading['amount'],
            occurredAt: $notification->getPostedAt()->format('Y-m-d\TH:i:s'),
            description: $reading['merchant'] ?? $notification->getTitle(),
            direction: $reading['kind'],
            timezone: $notification->getTimezone(),
            sourceAccountId: $out ? $account : null,
            destinationAccountId: $out ? null : $account,
            rawText: $notification->getFullText(),
            source: 'android',
            sourceName: $source->getAppName(),
        ), 'android', $source->getAppName());
        $notification->becameDraft($draft);
    }
}
