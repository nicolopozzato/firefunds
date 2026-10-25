<?php

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Who may register, and what only an administrator may do. The first user
 * becomes the administrator and closes registration; only an administrator
 * opens it again. The test database has no users: each test starts from an
 * empty instance.
 */
class RegistrationAndAdminTest extends WebTestCaseBase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testTheFirstUserIsTheAdministratorAndClosesRegistration(): void
    {
        $this->assertSame(['registrationAllowed' => true, 'hasUsers' => false], $this->request('GET', '/api/auth/check-registration'));

        $first = $this->register('first@example.test');
        $this->assertResponseStatusCodeSame(201);
        $this->assertTrue($first['isFirstUser']);
        $this->assertTrue($first['user']['isAdmin']);

        $this->assertSame(['registrationAllowed' => false, 'hasUsers' => true], $this->request('GET', '/api/auth/check-registration'));

        $second = $this->register('second@example.test');
        $this->assertResponseStatusCodeSame(403);
        $this->assertSame('registration_disabled', $second['code']);
    }

    public function testAnAdministratorReopensRegistration(): void
    {
        $this->register('admin@example.test');
        $admin = $this->user('admin@example.test');
        static::authenticate($this->client, $admin);

        $this->assertSame(['registrationEnabled' => false], $this->request('GET', '/api/admin/settings'));
        $this->assertSame(['registrationEnabled' => true], $this->request('PUT', '/api/admin/settings', ['registrationEnabled' => true]));

        $this->client->setServerParameter('HTTP_AUTHORIZATION', '');
        $joined = $this->register('joined@example.test');
        $this->assertResponseStatusCodeSame(201);
        $this->assertFalse($joined['user']['isAdmin']);

        static::authenticate($this->client, $admin);
        $users = $this->request('GET', '/api/admin/users');
        $this->assertSame(['admin@example.test', 'joined@example.test'], array_column($users, 'email'));
        $this->assertArrayNotHasKey('password', $users[0]);
    }

    public function testOnlyAnAdministratorReachesTheAdminEndpoints(): void
    {
        static::authenticate($this->client, static::createUser());

        foreach ([
            ['GET', '/api/admin/users', null],
            ['GET', '/api/admin/settings', null],
            ['PUT', '/api/admin/settings', ['registrationEnabled' => true]],
            ['GET', '/api/admin/backups', null],
            ['POST', '/api/admin/backups', null],
        ] as [$method, $uri, $body]) {
            $this->request($method, $uri, $body);
            $this->assertResponseStatusCodeSame(403, "$method $uri");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function register(string $email): array
    {
        return $this->request('POST', '/api/auth/register', [
            'firstName' => 'Test',
            'lastName' => 'User',
            'email' => $email,
            'password' => 'password-123',
        ]);
    }

    private function user(string $email): User
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<mixed>
     */
    private function request(string $method, string $uri, ?array $body = null): array
    {
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], null === $body ? null : json_encode($body));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
