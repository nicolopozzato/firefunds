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
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
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

        // Symfony's HTTP client as PSR-18, with nyholm/psr7's messages. The
        // endpoint is the user's to give: never an address of the server's own
        // networks (localhost holds Caddy's admin API), no redirect to one, and
        // a slow push service costs seconds, not minutes.
        $factory = new Psr17Factory();
        $client = new NoPrivateNetworkHttpClient($this->httpClient->withOptions([
            'max_redirects' => 0,
            'timeout' => 5,
            'max_duration' => 10,
        ]));
        $push = new WebPush(
            ['VAPID' => $this->keys->auth()],
            ['TTL' => 86400, 'urgency' => 'normal'],
            new Psr18Client($client, $factory, $factory),
            $factory,
            $factory,
        );
        $reached = 0;
        $now = $this->clock->now();
        // One device at a time: one that can't be sent to (keys the encryption
        // refuses) must not stop the others, nor the other users' notifications.
        foreach ($devices as $device) {
            try {
                $push->queueNotification(
                    Subscription::create([
                        'endpoint' => $device->getEndpoint(),
                        'publicKey' => $device->getPublicKey(),
                        'authToken' => $device->getAuthToken(),
                        'contentEncoding' => 'aes128gcm',
                    ]),
                    (string) json_encode($message),
                );
                foreach ($push->flush() as $report) {
                    if ($report->isSuccess()) {
                        $device->markSent($now);
                        ++$reached;
                    } elseif ($report->isSubscriptionExpired()) {
                        $this->entityManager->remove($device);
                    } else {
                        $this->logger->warning('Push to {host} failed: {reason}', ['host' => parse_url($device->getEndpoint(), \PHP_URL_HOST), 'reason' => $report->getReason()]);
                    }
                }
            } catch (\InvalidArgumentException $e) {
                // Keys the encryption refuses: never sendable, forgotten like a device its push service says is gone.
                $this->logger->warning('Push device {id} forgotten: {error}', ['id' => $device->getId()->toRfc4122(), 'error' => $e->getMessage()]);
                $this->entityManager->remove($device);
            } catch (\Throwable $e) {
                $this->logger->error('Push to device {id} failed: {error}', ['id' => $device->getId()->toRfc4122(), 'error' => $e->getMessage()]);
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
