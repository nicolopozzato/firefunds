<?php

namespace App\Tests\Unit\DTO;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentInstrumentType;
use App\Entity\InvestmentTransaction;
use App\Entity\InvestmentTransactionType;
use App\Entity\User;
use App\Transaction\DTO\TransactionResponse;
use PHPUnit\Framework\TestCase;

class TransactionResponseTest extends TestCase
{
    public function testFromInvestmentEntityExposesTradeBreakdown(): void
    {
        $user = new User();
        $broker = new Account($user, AccountType::Asset, 'Broker');
        $broker->setAssetKind(AssetAccountKind::Investment);
        $instrument = new InvestmentInstrument($user, 'Test ETF', InvestmentInstrumentType::Etf);

        $transaction = new InvestmentTransaction(
            user: $user,
            account: $broker,
            instrument: $instrument,
            type: InvestmentTransactionType::Sell,
            quantity: '2.500000',
            unitPrice: '100.0000',
            occurredAt: new \DateTimeImmutable('2026-09-01T10:00:00+00:00'),
        );
        $transaction->setFees('1.5000');
        $transaction->setTaxes('10.0000');

        $response = TransactionResponse::fromInvestmentEntity($transaction);

        $this->assertSame('investmentSell', $response->kind);
        $this->assertSame('2.500000', $response->quantity);
        $this->assertSame('100.0000', $response->unitPrice);
        $this->assertSame('1.5000', $response->fees);
        $this->assertSame('10.0000', $response->taxes);
        // A SELL's cash effect is net of fees and taxes.
        $this->assertSame('238.5000', $response->amount);
    }
}
