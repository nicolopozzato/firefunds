<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\WebTestCaseBase;

/** GET /api/investments/portfolio, the data behind Investments -> Overview (computed by PortfolioService). */
class PortfolioControllerTest extends WebTestCaseBase
{
    public function testReturnsThePortfolioOverTheRequestedRange(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('GET', '/api/investments/portfolio?from=2026-08-01&to=2026-09-30');

        $this->assertResponseIsSuccessful();
        $portfolio = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('0.0000', $portfolio['marketValue']);
        $this->assertNull($portfolio['netWorthShare'], 'no net worth to compare with');
        $this->assertSame([], $portfolio['positions']);
        $this->assertSame(['2026-08', '2026-09'], array_column($portfolio['monthlyFlows'], 'month'));
    }

    public function testInvalidRangeIsRejected(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('GET', '/api/investments/portfolio?from=not-a-date');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/investments/portfolio');

        $this->assertResponseStatusCodeSame(401);
    }
}
