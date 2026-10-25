<?php

namespace App\Tests\Unit\DTO;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\Currency;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentInstrumentType;
use App\Entity\InvestmentTransaction;
use App\Entity\InvestmentTransactionType;
use App\Entity\User;
use App\Investment\DTO\InvestmentTransactionResponse;
use PHPUnit\Framework\TestCase;

class InvestmentTransactionResponseTest extends TestCase
{
    public function testFromEntityCarriesTheAccountCurrency(): void
    {
        $user = new User();
        $broker = new Account($user, AccountType::Asset, 'Broker');
        $broker->setAssetKind(AssetAccountKind::Investment);
        $broker->setCurrency(new Currency('EUR', 'Euro', '€', 2));

        $transaction = new InvestmentTransaction(
            user: $user,
            account: $broker,
            instrument: new InvestmentInstrument($user, 'Test ETF', InvestmentInstrumentType::Etf),
            type: InvestmentTransactionType::Buy,
            quantity: '2',
            unitPrice: '100.0000',
            occurredAt: new \DateTimeImmutable('2026-09-01T10:00:00+00:00'),
        );
        $transaction->setFees('1.5000');
        $transaction->setTaxes('0.4000');

        $response = InvestmentTransactionResponse::fromEntity($transaction);

        $this->assertSame('EUR', $response->currency);
        $this->assertSame('0.4000', $response->taxes);
        // A BUY's cash effect includes fees and taxes.
        $this->assertSame('201.9000', $response->totalAmount);
    }
}
