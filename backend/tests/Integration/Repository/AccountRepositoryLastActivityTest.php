<?php

namespace App\Tests\Integration\Repository;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\Currency;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentInstrumentType;
use App\Entity\InvestmentTransaction;
use App\Entity\InvestmentTransactionType;
use App\Entity\Transaction;
use App\Entity\User;
use App\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class AccountRepositoryLastActivityTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private AccountRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(AccountRepository::class);
    }

    /**
     * A broker account funded on Sept 20 and then used to buy an ETF on Sept 22:
     * the BUY (an investment transaction, not a money one) is its last activity.
     */
    public function testInvestmentTradeCountsAsActivity(): void
    {
        $user = new User();
        $user->setFirstName('Activity');
        $user->setLastName('Test');
        $user->setEmail(\sprintf('activity-test-%s@example.test', uniqid()));
        $user->setPassword('hashed');
        $this->entityManager->persist($user);

        $currency = $this->entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']);
        if ($currency === null) {
            $currency = new Currency('EUR', 'Euro', '€', 2, true);
            $this->entityManager->persist($currency);
        }

        $checking = new Account($user, AccountType::Asset, 'Checking');
        $checking->setAssetKind(AssetAccountKind::Checking);
        $checking->setCurrency($currency);
        $this->entityManager->persist($checking);

        $broker = new Account($user, AccountType::Asset, 'Broker');
        $broker->setAssetKind(AssetAccountKind::Investment);
        $broker->setCurrency($currency);
        $this->entityManager->persist($broker);

        $instrument = new InvestmentInstrument($user, 'Activity ETF', InvestmentInstrumentType::Etf);
        $this->entityManager->persist($instrument);

        $this->entityManager->persist(new Transaction(
            user: $user,
            sourceAccount: $checking,
            destinationAccount: $broker,
            occurredAt: new \DateTimeImmutable('2026-09-20T10:00:00+00:00'),
            amount: '500.0000',
            currency: 'EUR',
        ));
        $this->entityManager->persist(new InvestmentTransaction(
            user: $user,
            account: $broker,
            instrument: $instrument,
            type: InvestmentTransactionType::Buy,
            quantity: '0.5',
            unitPrice: '160.0000',
            occurredAt: new \DateTimeImmutable('2026-09-22T09:00:00+00:00'),
        ));
        $this->entityManager->flush();

        $dates = $this->repository->getLastActivityDates([$checking, $broker]);

        $this->assertSame('2026-09-20 10:00:00', $dates[$checking->getId()->toRfc4122()]);
        $this->assertSame('2026-09-22 09:00:00', $dates[$broker->getId()->toRfc4122()]);
    }
}
