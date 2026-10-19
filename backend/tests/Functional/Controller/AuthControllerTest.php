<?php

namespace App\Tests\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class AuthControllerTest extends WebTestCase
{
    public function testRegisterEndpointExists(): void
    {
        $client = static::createClient();

        $userData = [
            'firstName' => 'Test' . uniqid(),
            'lastName' => 'User',
            'email' => 'test' . uniqid() . '@example.com',
            'password' => 'SecurePassword123!',
        ];

        $client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($userData)
        );

        // Should return 201 (created) or 403 (registration disabled after first user)
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertContains(
            $statusCode,
            [Response::HTTP_CREATED, Response::HTTP_FORBIDDEN],
            "Expected 201 or 403 for register endpoint, got {$statusCode}"
        );
    }

    public function testRegisterRequiresJsonContent(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/auth/register');

        // Should return 400 or 415 (bad request or unsupported media type)
        $this->assertTrue(
            $client->getResponse()->getStatusCode() >= 400
        );
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

    public function testLoginIsThrottledAfterFiveFailedAttempts(): void
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

        for ($i = 0; $i < 5; ++$i) {
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

        // Should return 400 (bad request) or 422 (unprocessable entity) for invalid email
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertContains(
            $statusCode,
            [Response::HTTP_BAD_REQUEST, Response::HTTP_UNPROCESSABLE_ENTITY],
            "Expected 400 or 422 for invalid email, got {$statusCode}"
        );
    }
}
