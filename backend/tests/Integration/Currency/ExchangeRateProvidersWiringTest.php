<?php

namespace App\Tests\Integration\Currency;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The tests leave the exchange rate service without providers (nothing is
 * fetched from the internet), so nothing else notices a provider that was
 * never tagged: the ECB first, Yahoo for what it doesn't have.
 */
class ExchangeRateProvidersWiringTest extends KernelTestCase
{
    public function testTheProvidersAreTaggedInPriorityOrder(): void
    {
        $application = new Application(self::bootKernel());
        $tester = new CommandTester($application->find('debug:container'));
        $tester->execute(['--tag' => 'app.exchange_rate_provider']);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('EcbExchangeRateProvider', $output);
        $this->assertStringContainsString('YahooExchangeRateProvider', $output);
        $this->assertLessThan(strpos($output, 'YahooExchangeRateProvider'), strpos($output, 'EcbExchangeRateProvider'), 'the ECB is asked first');
    }
}
