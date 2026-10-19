<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Saving the capital gains tax rate: the user's (preferences) and an
 * instrument's own.
 */
class CapitalGainsTaxRateTest extends WebTestCaseBase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::authenticate($this->client, static::createUser());
    }

    public function testPreferencesSaveTheRateAndKeepItWhenLeftOut(): void
    {
        $preferences = ['locale' => 'it', 'theme' => 'system', 'pageSize' => 50, 'timezone' => 'Europe/Rome', 'numberFormat' => 'it-IT'];

        $this->assertSame('26.0000', $this->send('PUT', '/api/auth/me/preferences', $preferences)['capitalGainsTaxRate']);
        $this->assertSame('12.5', $this->send('PUT', '/api/auth/me/preferences', $preferences + ['capitalGainsTaxRate' => '12.5'])['capitalGainsTaxRate']);
        $this->send('PUT', '/api/auth/me/preferences', $preferences);
        $this->assertSame('12.5000', $this->send('GET', '/api/auth/me')['capitalGainsTaxRate'], 'left out = unchanged');
    }

    public function testInstrumentHasItsOwnRateOrNone(): void
    {
        $created = $this->send('POST', '/api/investment-instruments', ['name' => 'XEON', 'type' => 'etf', 'capitalGainsTaxRate' => '15.46']);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('15.46', $created['capitalGainsTaxRate']);

        $updated = $this->send('PUT', '/api/investment-instruments/'.$created['id'], ['name' => 'XEON', 'type' => 'etf', 'capitalGainsTaxRate' => null]);
        $this->assertNull($updated['capitalGainsTaxRate'], 'empty = the user\'s rate');
    }

    public function testRateOutOfRangeIsRejected(): void
    {
        $this->send('POST', '/api/investment-instruments', ['name' => 'X', 'type' => 'etf', 'capitalGainsTaxRate' => '150']);

        $this->assertResponseStatusCodeSame(422);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $uri, ?array $body = null): array
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: $body !== null ? json_encode($body) : null);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
