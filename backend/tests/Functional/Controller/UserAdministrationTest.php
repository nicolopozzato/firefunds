<?php

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Administration → Users: an administrator creates users, edits them, sets
 * their password, disables and enables them. Nobody is deleted, and there is
 * always one active administrator.
 */
class UserAdministrationTest extends WebTestCaseBase
{
    private const PASSWORD = 'password-123';

    private KernelBrowser $client;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->admin = $this->createUserWithPassword('admin@example.test', admin: true);
        static::authenticate($this->client, $this->admin);
    }

    public function testAnAdministratorCreatesAUserWhoChoosesTheirPasswordAtTheFirstLogin(): void
    {
        $created = $this->request('POST', '/api/admin/users', [
            'firstName' => 'Anna',
            'lastName' => 'Rossi',
            'email' => 'Anna@Example.test',
            'password' => 'temporary-1',
            'mustChangePassword' => true,
        ]);
        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->assertSame('anna@example.test', $created['email']);
        $this->assertFalse($created['isAdmin']);
        $this->assertTrue($created['isActive']);
        $this->assertTrue($created['mustChangePassword']);

        $token = $this->login('anna@example.test', 'temporary-1')['token'];
        $this->assertTrue($this->request('GET', '/api/auth/me', token: $token)['mustChangePassword']);

        $refused = $this->request('GET', '/api/categories', token: $token);
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->assertSame('password_change_required', $refused['code']);

        // The same password again is no change.
        $same = $this->request('PUT', '/api/auth/me/password', ['newPassword' => 'temporary-1'], $token);
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $this->assertSame('password_unchanged', $same['code']);

        // No current password asked: she just logged in with it.
        $this->request('PUT', '/api/auth/me/password', ['newPassword' => 'her-own-password'], $token);
        $this->assertResponseIsSuccessful();

        $token = $this->login('anna@example.test', 'her-own-password')['token'];
        $this->assertFalse($this->request('GET', '/api/auth/me', token: $token)['mustChangePassword']);
        $this->request('GET', '/api/categories', token: $token);
        $this->assertResponseIsSuccessful();
    }

    public function testAPasswordKeptAsItIsNeedsNoChange(): void
    {
        $this->request('POST', '/api/admin/users', [
            'firstName' => 'Bruno',
            'lastName' => 'Bianchi',
            'email' => 'bruno@example.test',
            'password' => 'chosen-by-admin',
            'isAdmin' => true,
            'mustChangePassword' => false,
        ]);
        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $token = $this->login('bruno@example.test', 'chosen-by-admin')['token'];
        $me = $this->request('GET', '/api/auth/me', token: $token);
        $this->assertTrue($me['isAdmin']);
        $this->assertFalse($me['mustChangePassword']);
        $this->request('GET', '/api/admin/users', token: $token);
        $this->assertResponseIsSuccessful();
    }

    public function testATakenEmailOrAnInvalidUserIsRefused(): void
    {
        $taken = $this->request('POST', '/api/admin/users', [
            'firstName' => 'Other',
            'lastName' => 'Admin',
            'email' => 'ADMIN@example.test',
            'password' => 'password-123',
        ]);
        $this->assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $this->assertSame('email_already_registered', $taken['code']);

        $invalid = $this->request('POST', '/api/admin/users', [
            'firstName' => 'A',
            'lastName' => 'Valid',
            'email' => 'not-an-email',
            'password' => 'short',
        ]);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertEqualsCanonicalizing(
            ['firstName', 'email', 'password'],
            array_unique(array_column($invalid['violations'], 'propertyPath')),
        );
    }

    public function testAnAdministratorEditsAUser(): void
    {
        $user = $this->createUserWithPassword('carla@example.test');
        $this->createUserWithPassword('taken@example.test');

        $updated = $this->request('PUT', '/api/admin/users/'.$user->getId(), [
            'firstName' => 'Carla',
            'lastName' => 'Verdi',
            'email' => 'carla.verdi@example.test',
            'isAdmin' => true,
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSame(['Carla', 'Verdi', 'carla.verdi@example.test', true], [
            $updated['firstName'], $updated['lastName'], $updated['email'], $updated['isAdmin'],
        ]);

        // The new email logs in with the same password; the role is stored once.
        $this->login('carla.verdi@example.test', self::PASSWORD);
        $this->assertSame(['ROLE_ADMIN', 'ROLE_USER'], $this->reload($user)->getRoles());

        $taken = $this->request('PUT', '/api/admin/users/'.$user->getId(), [
            'firstName' => 'Carla',
            'lastName' => 'Verdi',
            'email' => 'Taken@example.test',
            'isAdmin' => false,
        ]);
        $this->assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $this->assertSame('email_already_registered', $taken['code']);

        $missing = $this->request('PUT', '/api/admin/users/0190a7e2-0000-7000-8000-000000000000', [
            'firstName' => 'Nobody',
            'lastName' => 'Here',
            'email' => 'nobody@example.test',
        ]);
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->assertSame('user_not_found', $missing['code']);
    }

    public function testANewPasswordEndsTheUsersSessions(): void
    {
        $user = $this->createUserWithPassword('dario@example.test');
        $session = $this->login('dario@example.test', self::PASSWORD);

        $reset = $this->request('PUT', '/api/admin/users/'.$user->getId().'/password', [
            'password' => 'new-password-1',
            'mustChangePassword' => true,
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertTrue($reset['mustChangePassword']);

        $this->request('POST', '/api/auth/refresh', ['refresh_token' => $session['refresh_token']]);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->request('POST', '/api/auth/login', ['email' => 'dario@example.test', 'password' => self::PASSWORD]);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->login('dario@example.test', 'new-password-1');
    }

    public function testADisabledUserCannotGetInAndIsListedLast(): void
    {
        $user = $this->createUserWithPassword('aaron@example.test');
        $session = $this->login('aaron@example.test', self::PASSWORD);

        $disabled = $this->request('POST', '/api/admin/users/'.$user->getId().'/disable');
        $this->assertResponseIsSuccessful();
        $this->assertFalse($disabled['isActive']);
        $this->assertNotNull($disabled['disabledAt']);

        // The access token still running and the refresh token stop at once.
        $this->request('GET', '/api/auth/me', token: $session['token']);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->request('POST', '/api/auth/refresh', ['refresh_token' => $session['refresh_token']]);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $refused = $this->request('POST', '/api/auth/login', ['email' => 'aaron@example.test', 'password' => self::PASSWORD]);
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->assertSame('account_disabled', $refused['code']);

        // A wrong password doesn't tell the account is disabled.
        $wrong = $this->request('POST', '/api/auth/login', ['email' => 'aaron@example.test', 'password' => 'wrong-password']);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->assertNotSame('account_disabled', $wrong['code'] ?? null);

        $this->assertSame(['admin@example.test', 'aaron@example.test'], array_column($this->request('GET', '/api/admin/users'), 'email'));

        $enabled = $this->request('POST', '/api/admin/users/'.$user->getId().'/enable');
        $this->assertTrue($enabled['isActive']);
        $this->assertNull($enabled['disabledAt']);
        $this->login('aaron@example.test', self::PASSWORD);
    }

    public function testTheLastActiveAdministratorStays(): void
    {
        $keepRole = $this->request('PUT', '/api/admin/users/'.$this->admin->getId(), [
            'firstName' => 'Admin',
            'lastName' => 'Test',
            'email' => 'admin@example.test',
            'isAdmin' => false,
        ]);
        $this->assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $this->assertSame('last_administrator', $keepRole['code']);

        $stayActive = $this->request('POST', '/api/admin/users/'.$this->admin->getId().'/disable');
        $this->assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $this->assertSame('last_administrator', $stayActive['code']);

        // A disabled administrator doesn't count.
        $other = $this->createUserWithPassword('other-admin@example.test', admin: true);
        $this->request('POST', '/api/admin/users/'.$other->getId().'/disable');
        $this->assertResponseIsSuccessful();
        $this->request('POST', '/api/admin/users/'.$this->admin->getId().'/disable');
        $this->assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        // With another active one, either can step down.
        $this->request('POST', '/api/admin/users/'.$other->getId().'/enable');
        $this->request('PUT', '/api/admin/users/'.$this->admin->getId(), [
            'firstName' => 'Admin',
            'lastName' => 'Test',
            'email' => 'admin@example.test',
            'isAdmin' => false,
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertFalse($this->reload($this->admin)->isAdmin());
    }

    public function testOnlyAnAdministratorManagesUsers(): void
    {
        $user = $this->createUserWithPassword('plain@example.test');
        static::authenticate($this->client, $user);

        foreach ([
            ['POST', '/api/admin/users', ['firstName' => 'New', 'lastName' => 'User', 'email' => 'new@example.test', 'password' => 'password-123']],
            ['PUT', '/api/admin/users/'.$user->getId(), ['firstName' => 'Plain', 'lastName' => 'User', 'email' => 'plain@example.test', 'isAdmin' => true]],
            ['PUT', '/api/admin/users/'.$this->admin->getId().'/password', ['password' => 'password-123']],
            ['POST', '/api/admin/users/'.$this->admin->getId().'/disable', null],
            ['POST', '/api/admin/users/'.$user->getId().'/enable', null],
        ] as [$method, $uri, $body]) {
            $this->request($method, $uri, $body);
            $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, "$method $uri");
        }
        $this->assertFalse($this->reload($user)->isAdmin());
    }

    public function testTheListSaysWhenEachUserLastUsedTheApp(): void
    {
        $user = $this->createUserWithPassword('elsa@example.test');
        $this->assertNull($this->userInList('elsa@example.test')['lastSeenAt']);

        $token = $this->login('elsa@example.test', self::PASSWORD)['token'];
        $seen = $this->userInList('elsa@example.test')['lastSeenAt'];
        $this->assertNotNull($seen);

        // Within a few minutes nothing is written again; later, any request records it.
        $this->setLastSeen($user, '-5 minutes');
        $this->request('GET', '/api/categories', token: $token);
        $this->assertEqualsWithDelta(time() - 300, $this->reload($user)->getLastSeenAt()->getTimestamp(), 5);

        $this->setLastSeen($user, '-2 days');
        $this->request('GET', '/api/categories', token: $token);
        $this->assertEqualsWithDelta(time(), $this->reload($user)->getLastSeenAt()->getTimestamp(), 5);
    }

    public function testChangingTheirOwnPasswordStillNeedsTheCurrentOne(): void
    {
        $user = $this->createUserWithPassword('ugo@example.test');
        $token = $this->login('ugo@example.test', self::PASSWORD)['token'];

        $refused = $this->request('PUT', '/api/auth/me/password', ['newPassword' => 'another-password'], $token);
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $this->assertSame('current_password_incorrect', $refused['code']);
        $this->assertFalse($this->reload($user)->mustChangePassword());
    }

    private function createUserWithPassword(string $email, bool $admin = false): User
    {
        $user = new User();
        $user->setFirstName('Admin');
        $user->setLastName('Test');
        $user->setEmail($email);
        $user->setRoles($admin ? ['ROLE_ADMIN'] : []);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function userInList(string $email): array
    {
        $users = $this->request('GET', '/api/admin/users');

        return $users[array_search($email, array_column($users, 'email'), true)];
    }

    private function setLastSeen(User $user, string $ago): void
    {
        static::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('UPDATE App\Entity\User u SET u.lastSeenAt = :at WHERE u.id = :id')
            ->setParameter('at', new \DateTimeImmutable($ago))
            ->setParameter('id', $user->getId(), 'uuid')
            ->execute();
    }

    private function reload(User $user): User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(User::class, $user->getId());
    }

    /**
     * @return array{token: string, refresh_token: string}
     */
    private function login(string $email, string $password): array
    {
        $tokens = $this->request('POST', '/api/auth/login', ['email' => $email, 'password' => $password]);
        $this->assertResponseIsSuccessful();

        return $tokens;
    }

    /**
     * Sends $token instead of the administrator's when given; login, refresh
     * and registration go without one.
     *
     * @param array<string, mixed>|null $body
     *
     * @return array<mixed>
     */
    private function request(string $method, string $uri, ?array $body = null, ?string $token = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        } elseif (str_starts_with($uri, '/api/auth/') && !str_starts_with($uri, '/api/auth/me')) {
            $server['HTTP_AUTHORIZATION'] = '';
        }

        $this->client->request($method, $uri, [], [], $server, null === $body ? null : json_encode($body));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
