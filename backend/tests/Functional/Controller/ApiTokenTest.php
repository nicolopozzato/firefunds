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

    public function testADraftsTokenIsNotToldWhichTransactionADraftMatched(): void
    {
        $token = $this->makeToken('Telefono', 'drafts')['token'];
        $account = $this->call('POST', '/api/accounts/asset', null, ['type' => 'asset', 'name' => 'Conto', 'currencyCode' => 'EUR', 'assetKind' => 'checking'], jwt: true);
        $this->call('POST', '/api/transactions', null, ['description' => 'Visita', 'sourceAccountId' => $account['id'], 'newDestinationAccountName' => 'Clinica', 'amount' => '42.17', 'occurredAt' => '2026-11-01T10:00:00', 'notes' => 'privata'], jwt: true);
        $this->assertResponseStatusCodeSame(201);

        $draft = $this->call('POST', '/api/drafts', $token, ['amount' => '42.17', 'occurredAt' => '2026-11-01T12:00:00']);
        $this->assertResponseStatusCodeSame(201);
        $this->assertNull($draft['possibleDuplicate']);
        $skipped = $this->call('POST', '/api/drafts', $token, ['amount' => '42.17', 'occurredAt' => '2026-11-01T12:00:00', 'ifExists' => 'skip']);
        $this->assertSame('matched', $skipped['status']);
        $this->assertSame([], $skipped['transactionIds']);

        // The user, reviewing it, sees what it matched.
        $this->assertSame('Visita', $this->call('GET', "/api/drafts/{$draft['id']}", jwt: true)['possibleDuplicate']['description']);
    }

    public function testAnEncodedPathDoesNotWidenTheScope(): void
    {
        $full = $this->makeToken('Script', 'full')['token'];
        $drafts = $this->makeToken('Telefono', 'drafts')['token'];
        $account = $this->call('POST', '/api/accounts/asset', null, ['type' => 'asset', 'name' => 'Conto', 'currencyCode' => 'EUR', 'assetKind' => 'checking'], jwt: true);

        // The router decodes the path: these are /api/tokens, /api/push/... and an account's history.
        $this->call('GET', '/api/%74okens', $full);
        $this->assertResponseStatusCodeSame(403);
        $this->call('POST', '/api/%74okens', $full, ['name' => 'x', 'scope' => 'full']);
        $this->assertResponseStatusCodeSame(403);
        $this->call('GET', '/api/%70ush/subscriptions', $full);
        $this->assertResponseStatusCodeSame(403);
        $this->call('GET', "/api/accounts/{$account['id']}%2Fbalance-history", $drafts);
        $this->assertResponseStatusCodeSame(403);
        $this->call('GET', "/api/accounts/{$account['id']}", $drafts);
        $this->assertResponseIsSuccessful();
    }

    public function testATokenIsRenamedAndKeepsWorking(): void
    {
        $token = $this->makeToken('Telefono', 'drafts');

        $renamed = $this->call('PUT', "/api/tokens/{$token['id']}", null, ['name' => '  Pixel di Nico  '], jwt: true);
        $this->assertResponseIsSuccessful();
        $this->assertSame('Pixel di Nico', $renamed['name']);
        $this->assertSame('Pixel di Nico', $this->call('GET', '/api/tokens', jwt: true)[0]['name']);

        $this->call('PUT', "/api/tokens/{$token['id']}", null, ['name' => ' '], jwt: true);
        $this->assertResponseStatusCodeSame(422);
        $this->call('PUT', "/api/tokens/{$token['id']}", $token['token'], ['name' => 'x']);
        $this->assertResponseStatusCodeSame(403, 'never through a token');

        $draft = $this->call('POST', '/api/drafts', $token['token'], ['amount' => '1.00', 'occurredAt' => '2026-10-28T12:00:00']);
        $this->assertSame('Pixel di Nico', $draft['sourceName']);
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
