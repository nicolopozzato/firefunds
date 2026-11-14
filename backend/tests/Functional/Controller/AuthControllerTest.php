<?php

namespace App\Tests\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class AuthControllerTest extends WebTestCase
{
    public function testRegisterRequiresJsonContent(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/auth/register');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testMeEndpointRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/auth/me');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testLoginEndpointExists(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'nonexistent@example.com',
                'password' => 'wrongpassword',
            ])
        );

        // Should return 401 (unauthorized) for invalid credentials
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testLoginIsThrottledAfterTenFailedAttempts(): void
    {
        $client = static::createClient();
        // The test limiter state lives in memory: keep one kernel for every request.
        $client->disableReboot();

        $attempt = static function () use ($client): void {
            $client->request(
                'POST',
                '/api/auth/login',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode(['email' => 'throttled@example.test', 'password' => 'wrongpassword'])
            );
        };

        for ($i = 0; $i < 10; ++$i) {
            $attempt();
            $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        }

        $attempt();
        $this->assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        $this->assertResponseHasHeader('Retry-After');
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('too_many_login_attempts', $data['code']);
        $this->assertGreaterThanOrEqual(1, $data['params']['minutes']);
    }

    public function testThrottlingCountsByEmailWhateverTheAddress(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $attempt = static function (string $email, string $ip) use ($client): void {
            $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip], content: (string) json_encode(['email' => $email, 'password' => 'wrongpassword']));
        };

        // A different address every time, as behind Cloudflare.
        for ($i = 1; $i <= 10; ++$i) {
            $attempt('throttled@example.test', "203.0.113.$i");
            $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        }
        $attempt('THROTTLED@example.test', '203.0.113.99');
        $this->assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS, 'the same email, any case, any address');

        $attempt('other@example.test', '203.0.113.1');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, 'another email has its own attempts');
    }

    public function testRegisterValidatesEmailFormat(): void
    {
        $client = static::createClient();

        $invalidEmailData = [
            'firstName' => 'Test',
            'lastName' => 'User',
            'email' => 'not-an-email',
            'password' => 'SecurePassword123!',
        ];

        $client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($invalidEmailData)
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $violations = json_decode($client->getResponse()->getContent(), true)['violations'];
        $this->assertSame([['email', 'invalid_email']], array_map(static fn (array $v) => [$v['propertyPath'], $v['code']], $violations));
    }
}
