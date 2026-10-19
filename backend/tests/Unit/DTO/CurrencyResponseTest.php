<?php

namespace App\Tests\Unit\DTO;

use App\Account\DTO\CurrencyResponse;
use App\Entity\Currency;
use PHPUnit\Framework\TestCase;

class CurrencyResponseTest extends TestCase
{
    public function testConstructorSetsAllProperties(): void
    {
        $response = new CurrencyResponse(
            code: 'EUR',
            name: 'Euro',
            symbol: '€',
            decimalPlaces: 2
        );

        $this->assertSame('EUR', $response->code);
        $this->assertSame('Euro', $response->name);
        $this->assertSame('€', $response->symbol);
        $this->assertSame(2, $response->decimalPlaces);
    }

    public function testFromEntityCreatesCorrectResponse(): void
    {
        $currency = new Currency(
            code: 'USD',
            name: 'US Dollar',
            symbol: '$',
            decimalPlaces: 2,
            isEnabled: true
        );

        $response = CurrencyResponse::fromEntity($currency);

        $this->assertInstanceOf(CurrencyResponse::class, $response);
        $this->assertSame('USD', $response->code);
        $this->assertSame('US Dollar', $response->name);
        $this->assertSame('$', $response->symbol);
        $this->assertSame(2, $response->decimalPlaces);
    }

    public function testFromEntityWithDifferentDecimalPlaces(): void
    {
        $currency = new Currency(
            code: 'JPY',
            name: 'Japanese Yen',
            symbol: '¥',
            decimalPlaces: 0,
            isEnabled: true
        );

        $response = CurrencyResponse::fromEntity($currency);

        $this->assertSame(0, $response->decimalPlaces);
    }

    public function testResponseIsReadonly(): void
    {
        $response = new CurrencyResponse(
            code: 'GBP',
            name: 'British Pound',
            symbol: '£',
            decimalPlaces: 2
        );

        $reflection = new \ReflectionClass($response);
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testResponseIsFinal(): void
    {
        $reflection = new \ReflectionClass(CurrencyResponse::class);
        $this->assertTrue($reflection->isFinal());
    }
}
