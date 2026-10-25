<?php

namespace App\Tests\Integration\Investment;

use App\Investment\Position\TradeCashEffect;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The SQL that sums trades and the PHP that sums trade rows are one rule
 * written twice (TradeCashEffect): they must give the same figure.
 */
class TradeCashEffectTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, string, string, string, string, string}>
     */
    public static function trades(): iterable
    {
        yield 'a buy pays amount and costs' => ['buy', '100.0000', '1.5000', '0.5000', '102.0000', '-102.0000'];
        yield 'a sell receives amount less costs' => ['sell', '100.0000', '1.5000', '0.5000', '98.0000', '98.0000'];
        yield 'no costs' => ['buy', '50.0000', '0', '0', '50.0000', '-50.0000'];
    }

    #[DataProvider('trades')]
    public function testSqlAndPhpAgree(string $type, string $amount, string $fees, string $taxes, string $total, string $signed): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $totalSql = TradeCashEffect::totalSql('it');
        $signedSql = TradeCashEffect::signedSql('it');
        $row = $connection->fetchAssociative(
            "SELECT CAST({$totalSql} AS numeric(19,4)) AS total, CAST({$signedSql} AS numeric(19,4)) AS signed
             FROM (SELECT CAST(? AS text) AS type, CAST(? AS numeric) AS amount, CAST(? AS numeric) AS fees, CAST(? AS numeric) AS taxes) it",
            [$type, $amount, $fees, $taxes],
        );
        $costs = bcadd($fees, $taxes, 4);

        $this->assertSame($total, $row['total']);
        $this->assertSame($signed, $row['signed']);
        $this->assertSame($total, TradeCashEffect::total($type, $amount, $costs));
        $this->assertSame($signed, TradeCashEffect::signed($type, $amount, $costs));
        $this->assertSame(bcsub('0', $signed, 4), TradeCashEffect::contribution($type, $amount, $costs));
    }
}
