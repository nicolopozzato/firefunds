<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Notification;
use App\Entity\PushSubscription;
use App\Entity\User;
use App\Notification\Push\PushDelivery;
use App\Notification\Push\PushSender;
use App\Notification\Service\NotificationCenter;
use App\Repository\NotificationRepository;
use App\Repository\PushSubscriptionRepository;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Push notifications: this device subscribing, the user's list, and when a
 * notification goes out (no real push service is reached: these users have
 * no device, so only the decisions are checked).
 */
class PushTest extends WebTestCaseBase
{
    use ClockSensitiveTrait;

    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        self::mockTime('2026-11-10 07:00:00 Europe/Rome');
        $this->client = static::createClient();
        $this->user = static::createUser();
        static::authenticate($this->client, $this->user);
    }

    public function testADeviceSubscribesOnceAndIsRemoved(): void
    {
        $this->client->request('GET', '/api/push/key');
        $key = json_decode((string) $this->client->getResponse()->getContent(), true)['publicKey'];
        $this->assertSame(87, \strlen($key));
        $this->client->request('GET', '/api/push/key');
        $this->assertSame($key, json_decode((string) $this->client->getResponse()->getContent(), true)['publicKey'], 'made once');

        $device = ['endpoint' => 'https://push.example.test/abc', 'keys' => self::browserKeys(), 'label' => 'Chrome su Android'];
        $this->post('/api/push/subscriptions', $device);
        $this->assertResponseStatusCodeSame(201);
        $this->post('/api/push/subscriptions', [...$device, 'label' => 'Chrome su Android (nuovo)']);
        $list = $this->get('/api/push/subscriptions');
        $this->assertCount(1, $list, 'the same device, renewed');
        $this->assertSame('Chrome su Android (nuovo)', $list[0]['label']);

        $this->post('/api/push/unsubscribe', ['endpoint' => $device['endpoint']]);
        $this->assertSame([], $this->get('/api/push/subscriptions'));
    }

    public function testKeysThatAreNotABrowsersAreRefused(): void
    {
        $this->post('/api/push/subscriptions', ['endpoint' => 'https://push.example.test/abc', 'keys' => ['p256dh' => 'AAAA', 'auth' => 'AAAA'], 'label' => 'x']);
        $this->assertResponseStatusCodeSame(422);
        $this->post('/api/push/subscriptions', ['endpoint' => 'https://push.example.test/abc', 'keys' => [...self::browserKeys(), 'auth' => 'AAAA'], 'label' => 'x']);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testTheEndpointIsAPushServicesNeverTheServersOwnNetwork(): void
    {
        foreach (['http://push.example.test/abc', 'http://127.0.0.1:2019/stop'] as $endpoint) {
            $this->post('/api/push/subscriptions', ['endpoint' => $endpoint, 'keys' => self::browserKeys(), 'label' => 'x']);
            $this->assertResponseStatusCodeSame(422, $endpoint);
        }

        // One saved before endpoints were checked: refused before any connection is made.
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = $entityManager->find(User::class, $this->user->getId());
        $keys = self::browserKeys();
        foreach (['https://127.0.0.1:2019/stop', 'https://localhost/stop', 'https://10.0.0.1/'] as $endpoint) {
            $entityManager->persist(new PushSubscription($user, $endpoint, $keys['p256dh'], $keys['auth'], 'Vecchio', new \DateTimeImmutable()));
        }
        $entityManager->flush();
        $this->assertSame(0, static::getContainer()->get(PushSender::class)->send($user, ['title' => 't', 'body' => 'b', 'url' => '/']));
    }

    public function testAUserHasAtMostTwentyDevices(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            $this->post('/api/push/subscriptions', ['endpoint' => "https://push.example.test/$i", 'keys' => self::browserKeys(), 'label' => "d$i"]);
            $this->assertResponseStatusCodeSame(201);
        }
        $this->post('/api/push/subscriptions', ['endpoint' => 'https://push.example.test/20', 'keys' => self::browserKeys(), 'label' => 'd20']);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('push_too_many_devices', json_decode((string) $this->client->getResponse()->getContent(), true)['code']);
        $this->post('/api/push/subscriptions', ['endpoint' => 'https://push.example.test/0', 'keys' => self::browserKeys(), 'label' => 'd0 again']);
        $this->assertResponseStatusCodeSame(201, 'the same device renewed is not a new one');
    }

    public function testADeviceThatCantBeSentToStopsNobodyElse(): void
    {
        // Saved before keys were checked: the encryption refuses it.
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $other = static::createUser();
        $entityManager->persist(new PushSubscription($other, 'https://push.example.test/broken', 'AAAA', 'AAAA', 'Vecchio', new \DateTimeImmutable()));
        static::getContainer()->get(NotificationCenter::class)->raise($other, 'draft_new', 'draft:other', ['draftId' => 'd', 'amount' => '1.00', 'description' => 'x']);
        $entityManager->flush();
        self::mockTime('2026-11-10 07:01:00 Europe/Rome');
        $this->raise('task_failing', 'task:prices');

        $this->assertSame(2, $this->deliver(), 'the alert after it still goes');
        $this->assertTrue($this->pushed('draft:other'));
        $this->assertTrue($this->pushed('task:prices'));
        $this->assertSame([], static::getContainer()->get(PushSubscriptionRepository::class)->findByUser($other), 'never sendable: forgotten');
    }

    public function testAPileOfNotificationsGoesOutPageByPage(): void
    {
        $center = static::getContainer()->get(NotificationCenter::class);
        $user = static::getContainer()->get(EntityManagerInterface::class)->find(User::class, $this->user->getId());
        for ($i = 0; $i < 450; ++$i) {
            $center->raise($user, 'draft_new', "draft:$i", ['draftId' => "$i", 'amount' => '1.00', 'description' => 'x']);
        }
        $this->raise('recurring_missing', 'recurring:a:2026-11-03');

        $this->assertSame(450, $this->deliver(), 'more than a page, every one');
        $this->assertTrue($this->pushed('draft:0'));
        $this->assertTrue($this->pushed('draft:449'));
        $this->assertFalse($this->pushed('recurring:a:2026-11-03'), 'the reminder still waits for its time');
        self::mockTime('2026-11-10 09:05:00 Europe/Rome');
        $this->assertSame(1, $this->deliver());
    }

    public function testRemindersWaitForTheSummaryTimeAndGoOnceADay(): void
    {
        $this->raise('recurring_missing', 'recurring:a:2026-11-03');

        $this->assertSame(0, $this->deliver(), 'before 09:00 where the user is');
        $this->assertFalse($this->pushed('recurring:a:2026-11-03'));

        self::mockTime('2026-11-10 09:05:00 Europe/Rome');
        $this->assertSame(1, $this->deliver());
        $this->assertTrue($this->pushed('recurring:a:2026-11-03'));

        $this->raise('recurring_missing', 'recurring:b:2026-11-08');
        $this->assertSame(0, $this->deliver(), 'one summary a day');
        self::mockTime('2026-11-11 09:05:00 Europe/Rome');
        $this->assertSame(1, $this->deliver());
    }

    public function testServerAlertsGoRightAwayAndChoicesAreRespected(): void
    {
        $this->client->request('PUT', '/api/auth/me/preferences', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['pushReminders' => false]));
        $this->assertResponseIsSuccessful();
        $this->assertFalse(json_decode((string) $this->client->getResponse()->getContent(), true)['pushReminders']);

        $this->raise('task_failing', 'task:prices');
        $this->raise('recurring_missing', 'recurring:a:2026-11-03');

        $this->assertSame(1, $this->deliver(), 'the alert at 07:00, without waiting');
        self::mockTime('2026-11-10 10:00:00 Europe/Rome');
        $this->assertSame(0, $this->deliver(), 'reminders are off');
        $this->assertTrue($this->pushed('recurring:a:2026-11-03'), 'left out, not waiting');
    }

    /** @return array{p256dh: string, auth: string} the shape a browser's PushSubscription.toJSON() has */
    private static function browserKeys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($key)['ec'];
        $b64url = static fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        // OpenSSL drops leading zero bytes: each coordinate is 32 bytes in the key.
        $coordinate = static fn (string $bytes) => str_pad($bytes, 32, "\0", \STR_PAD_LEFT);

        return ['p256dh' => $b64url("\x04".$coordinate($ec['x']).$coordinate($ec['y'])), 'auth' => $b64url(random_bytes(16))];
    }

    private function raise(string $type, string $key): void
    {
        $user = static::getContainer()->get(EntityManagerInterface::class)->find(User::class, $this->user->getId());
        static::getContainer()->get(NotificationCenter::class)->raise($user, $type, $key, ['recurringId' => 'a', 'description' => 'Bill', 'day' => '2026-11-03', 'task' => 'prices']);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function deliver(): int
    {
        return static::getContainer()->get(PushDelivery::class)->deliver();
    }

    private function pushed(string $key): bool
    {
        $notification = static::getContainer()->get(NotificationRepository::class)->findOneBy(['key' => $key]);
        $this->assertInstanceOf(Notification::class, $notification);

        return $notification->isPushed();
    }

    /** @param array<string, mixed> $body */
    private function post(string $url, array $body): void
    {
        $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($body));
    }

    /** @return array<mixed> */
    private function get(string $url): array
    {
        $this->client->request('GET', $url);
        $this->assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
