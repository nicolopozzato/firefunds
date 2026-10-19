<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\WebTestCaseBase;

class CurrencyControllerTest extends WebTestCaseBase
{
    public function testListCurrenciesRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/currencies');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testListCurrenciesReturnsJson(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/currencies');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($responseData);
    }

    public function testListCurrenciesReturnsArrayOfCurrencies(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/currencies');

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($responseData);

        // If there are currencies, check structure
        if (count($responseData) > 0) {
            $currency = $responseData[0];
            $this->assertArrayHasKey('code', $currency);
            $this->assertArrayHasKey('name', $currency);
            $this->assertArrayHasKey('symbol', $currency);
            $this->assertArrayHasKey('decimalPlaces', $currency);
        }
    }

    public function testListCurrenciesOnlyAcceptsGetMethod(): void
    {
        $client = static::createClient();

        // Test POST is not allowed
        $client->request('POST', '/api/currencies');
        $this->assertResponseStatusCodeSame(405);
    }

    public function testListCurrenciesPutMethodNotAllowed(): void
    {
        $client = static::createClient();

        $client->request('PUT', '/api/currencies');
        $this->assertResponseStatusCodeSame(405);
    }

    public function testListCurrenciesDeleteMethodNotAllowed(): void
    {
        $client = static::createClient();

        $client->request('DELETE', '/api/currencies');
        $this->assertResponseStatusCodeSame(405);
    }
}
