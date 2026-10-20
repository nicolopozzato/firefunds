<?php

namespace App\Tests\Functional\Controller;

use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentInstrumentType;
use App\Entity\InvestmentPrice;
use App\Entity\User;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The prices the user keeps by hand on an instrument's page: one per day,
 * correcting an imported one (which can be restored) or on a day of their
 * own (which can be removed, softly).
 */
class ManualPriceTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $user;
    private string $instrument;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->user = static::createUser();
        static::authenticate($this->client, $this->user);

        $instrument = new InvestmentInstrument($this->user, 'Fondo pensione', InvestmentInstrumentType::Fund);
        $this->entityManager->persist($instrument);
        $this->entityManager->persist(new InvestmentPrice($instrument, new \DateTimeImmutable('2026-10-01'), '10.0000'));
        $this->entityManager->flush();
        $this->instrument = $instrument->getId()->toRfc4122();
    }

    public function testADayOfTheUsersOwnCanBeSetChangedAndRemoved(): void
    {
        $this->assertSame(
            ['date' => '2026-10-05', 'price' => '12.5000', 'source' => 'manual', 'originalPrice' => null],
            $this->request('PUT', $this->url('2026-10-05'), ['price' => '12.5'])
        );
        // One per day: setting it again changes it.
        $this->request('PUT', $this->url('2026-10-05'), ['price' => '13']);
        $this->assertSame([['2026-10-01', '10.0000', 'market'], ['2026-10-05', '13.0000', 'manual']], $this->prices());

        $this->request('DELETE', $this->url('2026-10-05'));
        $this->assertResponseStatusCodeSame(204);
        $this->assertSame([['2026-10-01', '10.0000', 'market']], $this->prices());
        $this->assertNotNull($this->entityManager->getConnection()->fetchOne(
            'SELECT deleted_at FROM investment_price WHERE instrument_id = ? AND date = ?', [$this->instrument, '2026-10-05']
        ));

        // The day is free again.
        $this->request('PUT', $this->url('2026-10-05'), ['price' => '14']);
        $this->assertResponseIsSuccessful();
    }

    public function testAnImportedDayIsCorrectedAndRestoredButNeverRemoved(): void
    {
        $this->assertSame(
            ['date' => '2026-10-01', 'price' => '11.0000', 'source' => 'manual', 'originalPrice' => '10.0000'],
            $this->request('PUT', $this->url('2026-10-01'), ['price' => '11'])
        );

        $this->assertSame('price_from_provider', $this->request('DELETE', $this->url('2026-10-01'))['code']);
        $this->assertResponseStatusCodeSame(400);

        $this->assertSame(['10.0000', 'market'], array_values(array_intersect_key(
            $this->request('POST', $this->url('2026-10-01').'/restore'),
            ['price' => true, 'source' => true]
        )));
    }

    public function testRefusals(): void
    {
        $tomorrow = (new \DateTimeImmutable('tomorrow', new \DateTimeZone($this->user->getTimezone())))->format('Y-m-d');
        $this->assertSame('price_date_in_future', $this->request('PUT', $this->url($tomorrow), ['price' => '10'])['code']);

        $this->request('PUT', $this->url('2026-10-07'), ['price' => '10']);
        $this->assertSame('price_without_provider_value', $this->request('POST', $this->url('2026-10-07').'/restore')['code']);
        $this->assertSame('price_not_found', $this->request('DELETE', $this->url('2026-10-08'))['code']);
        $this->assertResponseStatusCodeSame(404);

        $this->assertSame('must_be_positive', $this->request('PUT', $this->url('2026-10-07'), ['price' => '0'])['violations'][0]['code']);

        static::authenticate($this->client, static::createUser());
        $this->request('PUT', $this->url('2026-10-07'), ['price' => '10']);
        $this->assertResponseStatusCodeSame(404);
    }

    private function url(string $day): string
    {
        return "/api/investment-instruments/{$this->instrument}/prices/{$day}";
    }

    /** @return array<array{0: string, 1: string, 2: string}> */
    private function prices(): array
    {
        return array_map(
            static fn (array $price) => [$price['date'], $price['price'], $price['source']],
            $this->request('GET', "/api/investment-instruments/{$this->instrument}/prices")
        );
    }

    private function request(string $method, string $uri, ?array $body = null): array
    {
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], null === $body ? null : (string) json_encode($body));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
