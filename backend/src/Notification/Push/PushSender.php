<?php

namespace App\Notification\Push;

use App\Core\Doctrine\PhysicalDelete;
use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends a push notification to every device of a user through their push
 * services (Web Push, encrypted, signed with the server's VAPID keys). A
 * device the service says is gone (404, 410) is forgotten.
 */
#[PhysicalDelete('A push subscription is a device\'s address, like a session token: forgotten when the push service says it no longer exists, or when the user removes the device.')]
class PushSender
{
    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly VapidKeys $keys,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @param array{title: string, body: string, url: string, tag?: string} $message
     * @param list<PushSubscription>|null $to only these devices (a test), else all the user's
     * @return int devices reached
     */
    public function send(User $user, array $message, ?array $to = null): int
    {
        $devices = $to ?? $this->subscriptions->findByUser($user);
        if ($devices === []) {
            return 0;
        }

        // Symfony's HTTP client as PSR-18 (the app's proxy and timeouts), with nyholm/psr7's messages.
        $factory = new Psr17Factory();
        $push = new WebPush(
            ['VAPID' => $this->keys->auth()],
            ['TTL' => 86400, 'urgency' => 'normal'],
            new Psr18Client($this->httpClient, $factory, $factory),
            $factory,
            $factory,
        );
        $byEndpoint = [];
        foreach ($devices as $device) {
            $byEndpoint[$device->getEndpoint()] = $device;
            $push->queueNotification(
                Subscription::create([
                    'endpoint' => $device->getEndpoint(),
                    'publicKey' => $device->getPublicKey(),
                    'authToken' => $device->getAuthToken(),
                    'contentEncoding' => 'aes128gcm',
                ]),
                (string) json_encode($message),
            );
        }

        $reached = 0;
        $now = $this->clock->now();
        foreach ($push->flush() as $report) {
            $device = $byEndpoint[$report->getEndpoint()] ?? null;
            if ($report->isSuccess()) {
                $device?->markSent($now);
                ++$reached;
            } elseif ($report->isSubscriptionExpired() && $device !== null) {
                $this->entityManager->remove($device);
            } else {
                $this->logger->warning('Push to {endpoint} failed: {reason}', ['endpoint' => $report->getEndpoint(), 'reason' => $report->getReason()]);
            }
        }
        $this->entityManager->flush();

        return $reached;
    }

    public function forget(PushSubscription $device): void
    {
        $this->entityManager->remove($device);
        $this->entityManager->flush();
    }
}
