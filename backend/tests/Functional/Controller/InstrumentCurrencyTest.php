<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The currency an instrument is quoted in, and the user's preferred one that
 * a new instrument starts with.
 */
class InstrumentCurrencyTest extends WebTestCaseBase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::authenticate($this->client, static::createUser());
    }

    public function testANewInstrumentStartsInTheUsersCurrency(): void
    {
        $created = $this->send('POST', '/api/investment-instruments', ['name' => 'XEON', 'type' => 'etf']);

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('EUR', $created['currencyCode']);
    }

    public function testTheChosenCurrencyIsKeptAndOnlyChangedWhenSent(): void
    {
        $created = $this->send('POST', '/api/investment-instruments', ['name' => 'S&P 500', 'type' => 'etf', 'currencyCode' => 'usd']);
        $this->assertSame('USD', $created['currencyCode']);

        $renamed = $this->send('PUT', '/api/investment-instruments/'.$created['id'], ['name' => 'S&P 500 UCITS', 'type' => 'etf']);
        $this->assertSame('USD', $renamed['currencyCode'], 'left out = unchanged');

        $changed = $this->send('PUT', '/api/investment-instruments/'.$created['id'], ['name' => 'S&P 500 UCITS', 'type' => 'etf', 'currencyCode' => 'CHF']);
        $this->assertSame('CHF', $changed['currencyCode']);
    }

    public function testACurrencyNotOnTheListIsRefused(): void
    {
        $response = $this->send('POST', '/api/investment-instruments', ['name' => 'X', 'type' => 'etf', 'currencyCode' => 'XXZ']);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('currency_not_available', $response['code']);
    }

    public function testNewInstrumentsFollowThePreferredCurrency(): void
    {
        $preferences = ['locale' => 'it', 'theme' => 'system', 'pageSize' => 50, 'timezone' => 'Europe/Rome', 'numberFormat' => 'it-IT'];

        $this->assertSame('EUR', $this->send('GET', '/api/auth/me')['currency']);
        $this->assertSame('USD', $this->send('PUT', '/api/auth/me/preferences', $preferences + ['currency' => 'usd'])['currency']);
        $this->assertSame('USD', $this->send('PUT', '/api/auth/me/preferences', $preferences)['currency'], 'left out = unchanged');

        $this->assertSame('USD', $this->send('POST', '/api/investment-instruments', ['name' => 'Tbill', 'type' => 'bond'])['currencyCode']);

        $this->send('PUT', '/api/auth/me/preferences', $preferences + ['currency' => 'XXZ']);
        $this->assertResponseStatusCodeSame(400);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function send(string $method, string $uri, ?array $body = null): array
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: $body !== null ? json_encode($body) : null);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
