<?php

namespace App\Tests\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The client's address login throttling counts by, behind a proxy and
 * behind Cloudflare: the visitor's, never one the client chose.
 */
class ClientIpTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // The test limiter state lives in memory: keep one kernel for every request.
        $this->client->disableReboot();
    }

    public function testBehindCloudflareTheVisitorIsCountedNotCloudflaresServers(): void
    {
        // Traefik (a private address) in front, which put Cloudflare's address in
        // X-Forwarded-For: a different Cloudflare server almost every request.
        for ($i = 1; $i <= 5; ++$i) {
            $this->failedLogin(['REMOTE_ADDR' => '172.18.0.2', 'HTTP_X_FORWARDED_FOR' => "162.158.0.$i", 'HTTP_CF_CONNECTING_IP' => '203.0.113.7']);
            $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        }
        $this->failedLogin(['REMOTE_ADDR' => '172.18.0.2', 'HTTP_X_FORWARDED_FOR' => '162.158.0.99', 'HTTP_CF_CONNECTING_IP' => '203.0.113.7']);
        $this->assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS, 'the same visitor, through any Cloudflare server');

        $this->failedLogin(['REMOTE_ADDR' => '172.18.0.2', 'HTTP_X_FORWARDED_FOR' => '162.158.0.1', 'HTTP_CF_CONNECTING_IP' => '203.0.113.8']);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, 'another visitor has their own attempts');
    }

    public function testTheHeaderIsIgnoredWhenTheRequestDidNotComeFromCloudflare(): void
    {
        // Six different real addresses, all claiming the same CF-Connecting-IP:
        // if the header were believed, the sixth would be refused.
        for ($i = 1; $i <= 6; ++$i) {
            $this->failedLogin(['REMOTE_ADDR' => '172.18.0.2', 'HTTP_X_FORWARDED_FOR' => "198.51.100.$i", 'HTTP_CF_CONNECTING_IP' => '203.0.113.7']);
            $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        }
    }

    /** @param array<string, string> $server */
    private function failedLogin(array $server): void
    {
        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json', ...$server], content: (string) json_encode(['email' => 'throttled@example.test', 'password' => 'wrongpassword']));
    }
}
