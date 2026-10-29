<?php

namespace App\Tests\Unit\Service;

use App\BankNotification\Service\NotificationReading;
use PHPUnit\Framework\TestCase;

class NotificationReadingTest extends TestCase
{
    public function testTheGenericReadingUnderstandsCommonBankTexts(): void
    {
        $this->assertSame(
            ['kind' => 'out', 'amount' => '50.00', 'merchant' => 'RISTORANTE DA MARIO', 'rule' => null],
            NotificationReading::read([], "Intesa Sanpaolo\nPagamento di 50,00 EUR presso RISTORANTE DA MARIO con carta *1234"),
        );
        $this->assertSame('Bar Centrale', NotificationReading::read([], 'Hai pagato 12,40 € a Bar Centrale')['merchant']);
        $this->assertSame(['kind' => 'in', 'amount' => '1500.00'], array_slice(NotificationReading::read([], 'Hai ricevuto un bonifico di € 1.500,00 da Acme Srl') ?? [], 0, 2));
        $this->assertNull(NotificationReading::read([], 'Nuovo accesso al tuo conto da un nuovo dispositivo'), 'no amount');
    }

    public function testARuleWinsAndCanIgnore(): void
    {
        $rules = [
            ['kind' => 'ignore', 'pattern' => 'Il tuo codice è {*}'],
            ['kind' => 'out', 'pattern' => 'Transazione di {importo} EUR autorizzata su {esercente}'],
        ];

        $this->assertSame('ignore', NotificationReading::read($rules, 'Il tuo codice è 123456 per 5,00 EUR')['kind']);
        $this->assertSame(
            ['kind' => 'out', 'amount' => '4.99', 'merchant' => 'ILIAD', 'rule' => 1],
            NotificationReading::read($rules, 'Transazione di 4,99 EUR autorizzata su ILIAD.'),
        );
    }

    public function testAmountsInEitherWriting(): void
    {
        $this->assertSame('1234.56', NotificationReading::amount('1.234,56'));
        $this->assertSame('1234.56', NotificationReading::amount('1,234.56'));
        $this->assertSame('4.99', NotificationReading::amount('4.99'));
        $this->assertSame('1234', NotificationReading::amount('1.234'));
        $this->assertNull(NotificationReading::amount('0,00'));
    }
}
