<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\Currency;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/** The phone's notifications: read into drafts, kept once, taught with a rule on the Sources page. */
class BankNotificationTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private string $checking;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $user = static::createUser();
        static::authenticate($this->client, $user);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $currency = $entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']) ?? new Currency('EUR', 'Euro', '€', 2);
        $entityManager->persist($currency);
        $checking = new Account($user, AccountType::Asset, 'Checking');
        $checking->setAssetKind(AssetAccountKind::Checking);
        $checking->setCurrency($currency);
        $entityManager->persist($checking);
        $entityManager->flush();
        $this->checking = (string) $checking->getId();
        $this->token = $this->send('POST', '/api/tokens', ['name' => 'Telefono', 'scope' => 'drafts'])['token'];
    }

    public function testAPaymentBecomesADraftOnTheSourcesAccount(): void
    {
        $first = $this->notify('Pagamento di 50,00 EUR presso RISTORANTE DA MARIO con carta *1234', 'k1');
        $this->assertSame('draft', $first['status'], 'read even before the source has an account');

        $source = $this->send('GET', '/api/sources')[0];
        $this->assertSame('Intesa Sanpaolo', $source['appName']);
        $this->send('PUT', "/api/sources/{$source['id']}", ['accountId' => $this->checking, 'rules' => []]);

        $second = $this->notify('Pagamento di 12,40 EUR presso BAR CENTRALE', 'k2');
        $draft = $this->send('GET', "/api/drafts/{$second['draftId']}");
        $this->assertSame('12.4000', $draft['amount']);
        $this->assertSame('BAR CENTRALE', $draft['description']);
        $this->assertSame('out', $draft['direction']);
        $this->assertSame($this->checking, $draft['sourceAccount']['id']);
        $this->assertSame('android', $draft['source']);

        $this->assertSame($second['id'], $this->notify('Pagamento di 12,40 EUR presso BAR CENTRALE', 'k2')['id'], 'sent twice, kept once');
        $this->assertCount(2, $this->send('GET', '/api/drafts'));
    }

    public function testANotificationNotUnderstoodWaitsForARule(): void
    {
        $this->assertSame('unread', $this->notify('Operazione autorizzata: 4,99 su ILIAD', 'k1')['status']);
        $this->assertSame('unread', $this->notify('Il tuo codice OTP è 123456', 'k2')['status']);
        $source = $this->send('GET', '/api/sources')[0];
        $this->assertSame(2, $source['unread']);

        $rules = [
            ['kind' => 'ignore', 'pattern' => 'codice OTP'],
            ['kind' => 'out', 'pattern' => 'Operazione autorizzata: {importo} su {esercente}'],
        ];
        $preview = $this->send('POST', "/api/sources/{$source['id']}/preview", ['rules' => $rules]);
        $this->assertSame(['ignore', 'out'], array_map(static fn (array $n) => $n['reading']['kind'], $preview['notifications']));
        $this->assertSame([['matches' => 1, 'lastMatch' => '2026-10-29T12:30:00'], ['matches' => 1, 'lastMatch' => '2026-10-29T12:30:00']], $preview['ruleStats']);

        $shadowed = [['kind' => 'ignore', 'pattern' => '{*}'], ...$rules];
        $this->assertSame([2, 0, 0], array_column($this->send('POST', "/api/sources/{$source['id']}/preview", ['rules' => $shadowed])['ruleStats'], 'matches'), 'a notification counts for the first rule that reads it');

        $this->send('PUT', "/api/sources/{$source['id']}", ['rules' => $rules]);
        $this->assertSame(['drafts' => 1], $this->send('POST', "/api/sources/{$source['id']}/reread"));
        $this->assertSame(0, $this->send('GET', '/api/sources')[0]['unread']);
    }

    public function testANotificationSaysWhatItsDraftBecame(): void
    {
        $first = $this->notify('Pagamento di 50,00 EUR presso RISTORANTE DA MARIO', 'k1');
        $source = $this->send('GET', '/api/sources')[0];
        $this->assertSame(['id' => $first['draftId'], 'status' => 'pending', 'transactionId' => null], $this->send('GET', "/api/sources/{$source['id']}")['notifications'][0]['draft']);

        $this->send('POST', "/api/drafts/{$first['draftId']}/discard");
        $this->assertSame('discarded', $this->send('GET', "/api/sources/{$source['id']}")['notifications'][0]['draft']['status']);
    }

    public function testDeletingASourceRemovesItsNotificationsAndDiscardsItsWaitingDrafts(): void
    {
        $draftId = $this->notify('Pagamento di 5,00 EUR presso MARIO', 'k1')['draftId'];
        $source = $this->send('GET', '/api/sources')[0];

        $this->client->request('DELETE', "/api/sources/{$source['id']}");
        $this->assertResponseStatusCodeSame(204);
        $this->assertSame([], $this->send('GET', '/api/sources'));
        $this->assertSame('discarded', $this->send('GET', "/api/drafts/{$draftId}")['status']);
        $this->assertSame([], $this->send('GET', '/api/drafts'));

        $this->assertSame('draft', $this->notify('Pagamento di 5,00 EUR presso MARIO', 'k1')['status'], 'a new notification makes the source again');
    }

    /** @return array<string, mixed> */
    private function notify(string $text, string $key): array
    {
        $this->client->request('POST', '/api/bank-notifications', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: (string) json_encode([
            'package' => 'com.latuabancaperandroid', 'appName' => 'Intesa Sanpaolo', 'title' => 'Intesa Sanpaolo',
            'text' => $text, 'postedAt' => '2026-10-29T12:30:00', 'timezone' => 'Europe/Rome', 'key' => $key,
        ]));
        $this->assertResponseStatusCodeSame(201);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /**
     * As the logged-in user (the JWT the client already sends).
     *
     * @param array<string, mixed>|null $body
     * @return array<mixed>
     */
    private function send(string $method, string $url, ?array $body = null): array
    {
        $this->client->request($method, $url, server: $body !== null ? ['CONTENT_TYPE' => 'application/json'] : [], content: $body !== null ? (string) json_encode($body) : null);
        $this->assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
