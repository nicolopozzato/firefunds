<?php

namespace App\Tests\Integration\Repository;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\Currency;
use App\Entity\Transaction;
use App\Entity\User;
use App\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class AccountRepositoryDailyBalanceTimezoneTest extends KernelTestCase
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
     * A transaction made at 00:20 Europe/Rome (CEST, UTC+2) on Sept 29 is stored
     * as 22:20 UTC on Sept 28. The daily balance history must still attribute it
     * to Sept 29 (the user's own calendar day), not Sept 28 (the UTC day).
     */
    public function testTransactionNearMidnightLandsOnTheUsersLocalDay(): void
    {
        $user = new User();
        $user->setFirstName('TZ');
        $user->setLastName('Test');
        $user->setEmail(\sprintf('tz-test-%s@example.test', uniqid()));
        $user->setPassword('hashed');
        $user->setTimezone('Europe/Rome');
        $this->entityManager->persist($user);

        $currency = $this->entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']);
        if ($currency === null) {
            $currency = new Currency('EUR', 'Euro', '€', 2, true);
            $this->entityManager->persist($currency);
        }

        $checking = new Account($user, AccountType::Asset, 'Checking');
        $checking->setAssetKind(AssetAccountKind::Checking);
        $checking->setCurrency($currency);
        $checking->setOpeningBalance('0.0000');
        $this->entityManager->persist($checking);

        $revenue = new Account($user, AccountType::Revenue, 'Salary');
        $this->entityManager->persist($revenue);

        // 00:20 Europe/Rome on 2026-09-29 (CEST, UTC+2) = 22:20 UTC on 2026-09-28.
        $occurredAt = new \DateTimeImmutable('2026-09-29T00:20:00+02:00');
        $occurredAt = $occurredAt->setTimezone(new \DateTimeZone('UTC'));

        $transaction = new Transaction(
            user: $user,
            sourceAccount: $revenue,
            destinationAccount: $checking,
            occurredAt: $occurredAt,
            amount: '500.0000',
            currency: 'EUR',
        );
        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        $history = $this->repository->getDailyBalanceHistory(
            $checking,
            new \DateTimeImmutable('2026-09-27'),
            new \DateTimeImmutable('2026-09-30'),
        );

        $byDate = [];
        foreach ($history as $point) {
            $byDate[$point['date']] = $point['balance'];
        }

        $this->assertSame('0.0000', $byDate['2026-09-28']);
        $this->assertSame('500.0000', $byDate['2026-09-29']);
    }
}
