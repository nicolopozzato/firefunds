<?php

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Sessions: login, refresh, logout, and what a change of email or password
 * does to them. Tokens name the user by id (User::getUserIdentifier), refresh
 * tokens are single use, and a spent one sent again revokes its whole chain.
 */
class AuthSessionTest extends WebTestCaseBase
{
    private const PASSWORD = 'password-123';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testLoginIssuesTokensNamingTheUserById(): void
    {
        $user = $this->createUserWithPassword();

        $tokens = $this->login($user->getEmail());

        $payload = static::getContainer()->get(JWTEncoderInterface::class)->decode($tokens['token']);
        $this->assertSame($user->getId()->toRfc4122(), $payload['username']);
        $this->assertSame((string) $user->getId(), $this->me($tokens['token'])['id']);
    }

    public function testLoginIgnoresTheCaseOfTheEmail(): void
    {
        $user = $this->createUserWithPassword();

        $this->login(strtoupper($user->getEmail()));
    }

    /**
     * Before, tokens named the email: once A moved to another address and B
     * took the old one, A's tokens opened B's account.
     */
    public function testTokensStayWithTheirUserWhenTheEmailChangesHands(): void
    {
        $a = $this->createUserWithPassword();
        $oldEmail = $a->getEmail();
        $tokens = $this->login($oldEmail);

        $this->request('PUT', '/api/auth/me', [
            'firstName' => 'Anna',
            'lastName' => 'Rossi',
            'email' => 'moved-'.uniqid().'@example.test',
            'currentPassword' => self::PASSWORD,
        ], $tokens['token']);
        $this->assertResponseIsSuccessful();
        $this->createUserWithPassword($oldEmail);

        $this->assertSame((string) $a->getId(), $this->me($tokens['token'])['id']);
        $refreshed = $this->refresh($tokens['refresh_token']);
        $this->assertResponseIsSuccessful();
        $this->assertSame((string) $a->getId(), $this->me($refreshed['token'])['id']);
    }

    public function testATokenNamingAnEmailIsRefused(): void
    {
        $user = $this->createUserWithPassword();
        $legacy = static::getContainer()->get(JWTEncoderInterface::class)->encode([
            'username' => $user->getEmail(),
            'iat' => time(),
            'exp' => time() + 600,
        ]);

        $this->request('GET', '/api/auth/me', null, $legacy);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testRefreshReplacesTheTokenAndASpentOneEndsTheSession(): void
    {
        $user = $this->createUserWithPassword();
        $first = $this->login($user->getEmail())['refresh_token'];

        $second = $this->refresh($first);
        $this->assertResponseIsSuccessful();
        $this->assertNotSame($first, $second['refresh_token']);
        $this->assertSame((string) $user->getId(), $this->me($second['token'])['id']);

        // The spent token again: someone has a copy, so the chain is revoked.
        $this->refresh($first);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->refresh($second['refresh_token']);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testLogoutRevokesTheRefreshToken(): void
    {
        $user = $this->createUserWithPassword();
        $tokens = $this->login($user->getEmail());

        // Sent without the access token, which may have expired already.
        $this->request('POST', '/api/auth/logout', ['refresh_token' => $tokens['refresh_token']]);
        $this->assertResponseIsSuccessful();

        $this->refresh($tokens['refresh_token']);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testChangingThePasswordSignsOutEveryDevice(): void
    {
        $user = $this->createUserWithPassword();
        $phone = $this->login($user->getEmail());
        $laptop = $this->login($user->getEmail());

        $this->request('PUT', '/api/auth/me/password', [
            'currentPassword' => self::PASSWORD,
            'newPassword' => 'new-password-456',
        ], $laptop['token']);
        $this->assertResponseIsSuccessful();

        $this->refresh($phone['refresh_token']);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->refresh($laptop['refresh_token']);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testANewEmailNeedsTheCurrentPassword(): void
    {
        $user = $this->createUserWithPassword();
        $token = $this->login($user->getEmail())['token'];
        $profile = ['firstName' => 'Anna', 'lastName' => 'Rossi', 'email' => 'new-'.uniqid().'@example.test'];

        $this->request('PUT', '/api/auth/me', $profile, $token);
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $this->assertSame('current_password_incorrect', $this->json()['code']);

        $this->request('PUT', '/api/auth/me', $profile + ['currentPassword' => 'wrong-password'], $token);
        $this->assertSame('current_password_incorrect', $this->json()['code']);

        $this->request('PUT', '/api/auth/me', $profile + ['currentPassword' => self::PASSWORD], $token);
        $this->assertResponseIsSuccessful();
        $this->assertSame($profile['email'], $this->json()['email']);
    }

    public function testTheNameChangesWithoutThePassword(): void
    {
        $user = $this->createUserWithPassword();
        $token = $this->login($user->getEmail())['token'];

        $this->request('PUT', '/api/auth/me', [
            'firstName' => 'Anna',
            'lastName' => 'Bianchi',
            'email' => strtoupper($user->getEmail()),
        ], $token);

        $this->assertResponseIsSuccessful();
        $this->assertSame('Bianchi', $this->json()['lastName']);
    }

    public function testAnEmailTakenInAnotherCaseIsAConflict(): void
    {
        $user = $this->createUserWithPassword();
        $other = $this->createUserWithPassword();
        $token = $this->login($user->getEmail())['token'];

        $this->request('PUT', '/api/auth/me', [
            'firstName' => 'Anna',
            'lastName' => 'Rossi',
            'email' => strtoupper($other->getEmail()),
            'currentPassword' => self::PASSWORD,
        ], $token);

        $this->assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $this->assertSame('email_already_registered', $this->json()['code']);
    }

    private function createUserWithPassword(?string $email = null): User
    {
        $user = new User();
        $user->setFirstName('Session');
        $user->setLastName('Test');
        $user->setEmail($email ?? \sprintf('session-%s@example.test', uniqid()));
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    /**
     * @return array{token: string, refresh_token: string}
     */
    private function login(string $email): array
    {
        $this->request('POST', '/api/auth/login', ['email' => $email, 'password' => self::PASSWORD]);
        $this->assertResponseIsSuccessful();

        return $this->json();
    }

    /**
     * @return array{token?: string, refresh_token?: string}
     */
    private function refresh(string $refreshToken): array
    {
        $this->request('POST', '/api/auth/refresh', ['refresh_token' => $refreshToken]);

        return $this->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function me(string $token): array
    {
        $this->request('GET', '/api/auth/me', null, $token);
        $this->assertResponseIsSuccessful();

        return $this->json();
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $uri, ?array $body = null, ?string $token = null): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        $this->client->request($method, $uri, [], [], $server, null === $body ? null : json_encode($body));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }
}
