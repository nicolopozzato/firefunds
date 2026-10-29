<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/** Personal access tokens: made once, used as Bearer, held to their scope, revoked. */
class ApiTokenTest extends WebTestCaseBase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::authenticate($this->client, static::createUser());
    }

    public function testAFullTokenActsAsItsUserUntilRevoked(): void
    {
        $token = $this->makeToken('Gestionale', 'full');
        $this->assertStringStartsWith('ff_', $token['token']);
        $this->assertSame(43, \strlen($token['token']));
        $this->assertNotContains('token', array_keys($this->call('GET', '/api/tokens', jwt: true)[0]), 'shown once only');

        $this->call('GET', '/api/accounts/asset', $token['token']);
        $this->assertResponseIsSuccessful();
        $this->call('GET', '/api/tokens', $token['token']);
        $this->assertResponseStatusCodeSame(403, 'a token never manages tokens');

        $this->call('DELETE', "/api/tokens/{$token['id']}", jwt: true);
        $this->call('GET', '/api/accounts/asset', $token['token']);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testADraftsTokenOnlyMakesDrafts(): void
    {
        $token = $this->makeToken('Telefono', 'drafts')['token'];

        $this->call('POST', '/api/drafts', $token, ['amount' => '12.50', 'occurredAt' => '2026-10-28T12:00:00', 'description' => 'Bar']);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('Telefono', json_decode((string) $this->client->getResponse()->getContent(), true)['sourceName']);

        $this->call('GET', '/api/transactions', $token);
        $this->assertResponseStatusCodeSame(403);
        $this->call('GET', '/api/categories', $token);
        $this->assertResponseIsSuccessful();
    }

    public function testAnUnknownTokenIsRefused(): void
    {
        $this->call('GET', '/api/accounts/asset', 'ff_'.str_repeat('x', 40));
        $this->assertResponseStatusCodeSame(401);
    }

    /** @return array<string, mixed> */
    private function makeToken(string $name, string $scope): array
    {
        $created = $this->call('POST', '/api/tokens', null, ['name' => $name, 'scope' => $scope], jwt: true);
        $this->assertResponseStatusCodeSame(201);

        return $created;
    }

    /**
     * With a personal token, or (`jwt`) the logged-in user's JWT the client already sends.
     *
     * @param array<string, mixed>|null $body
     * @return array<mixed>
     */
    private function call(string $method, string $url, ?string $token = null, ?array $body = null, bool $jwt = false): array
    {
        $server = $body !== null ? ['CONTENT_TYPE' => 'application/json'] : [];
        if (!$jwt) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $this->client->request($method, $url, server: $server, content: $body !== null ? (string) json_encode($body) : null);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
