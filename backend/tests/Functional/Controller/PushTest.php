<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Notification\Push\PushDelivery;
use App\Notification\Service\NotificationCenter;
use App\Repository\NotificationRepository;
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

        $device = ['endpoint' => 'https://push.example.test/abc', 'keys' => ['p256dh' => 'BPublic', 'auth' => 'secret'], 'label' => 'Chrome su Android'];
        $this->post('/api/push/subscriptions', $device);
        $this->assertResponseStatusCodeSame(201);
        $this->post('/api/push/subscriptions', [...$device, 'label' => 'Chrome su Android (nuovo)']);
        $list = $this->get('/api/push/subscriptions');
        $this->assertCount(1, $list, 'the same device, renewed');
        $this->assertSame('Chrome su Android (nuovo)', $list[0]['label']);

        $this->post('/api/push/unsubscribe', ['endpoint' => $device['endpoint']]);
        $this->assertSame([], $this->get('/api/push/subscriptions'));
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
