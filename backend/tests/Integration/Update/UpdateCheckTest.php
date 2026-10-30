<?php

namespace App\Tests\Integration\Update;

use App\Repository\SettingRepository;
use App\Update\GitHubReleases;
use App\Update\UpdateCheck;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The latest release on GitHub against the version running: kept until the
 * next check, newer only between real releases, nothing while turned off.
 */
class UpdateCheckTest extends KernelTestCase
{
    public function testANewerReleaseIsAnUpdate(): void
    {
        $check = $this->check('0.9.0', new JsonMockResponse([
            'tag_name' => 'v0.10.0',
            'html_url' => 'https://github.com/nicolopozzato/firefunds/releases/tag/v0.10.0',
            'published_at' => '2026-10-30T10:00:00Z',
        ]));

        $this->assertSame('0.10.0', $check->check()?->version);

        $status = $check->status();
        $this->assertTrue($status->updateAvailable);
        $this->assertSame('0.9.0', $status->currentVersion);
        $this->assertSame('https://github.com/nicolopozzato/firefunds/releases/tag/v0.10.0', $status->latest?->url);
        $this->assertSame('2026-10-30T10:00:00Z', $status->latest?->publishedAt);
        $this->assertSame('2026-10-30T12:00:00+00:00', $status->checkedAt);
    }

    public function testTheVersionRunningIsNoUpdate(): void
    {
        $check = $this->check('0.10.0', new JsonMockResponse(['tag_name' => 'v0.10.0', 'html_url' => 'https://example.test', 'published_at' => null]));
        $check->check();

        $this->assertFalse($check->status()->updateAvailable);
        $this->assertSame('0.10.0', $check->status()->latest?->version);
    }

    public function testNoReleaseYetIsNoUpdate(): void
    {
        $check = $this->check('0.9.0', new MockResponse('{"message":"Not Found"}', ['http_code' => 404]));

        $this->assertNull($check->check());
        $this->assertNull($check->status()->latest);
        $this->assertNotNull($check->status()->checkedAt);
    }

    public function testTurnedOffShowsNothing(): void
    {
        $check = $this->check('0.9.0', new JsonMockResponse(['tag_name' => 'v1.0.0', 'html_url' => 'https://example.test']));
        $check->check();
        $check->setEnabled(false);

        $status = $check->status();
        $this->assertFalse($status->enabled);
        $this->assertNull($status->latest);
        $this->assertFalse($status->updateAvailable);
    }

    public function testOnlyReleasesAreCompared(): void
    {
        $this->assertTrue(UpdateCheck::isNewer('0.10.0', '0.9.0'));
        $this->assertTrue(UpdateCheck::isNewer('1.0.0', '0.99.9'));
        $this->assertFalse(UpdateCheck::isNewer('0.9.0', '0.9.0'));
        $this->assertFalse(UpdateCheck::isNewer('0.8.1', '0.9.0'));
        // A development build ("dev") never has an update.
        $this->assertFalse(UpdateCheck::isNewer('0.10.0', 'dev'));
        $this->assertFalse(UpdateCheck::isNewer('0.10.0-rc1', '0.9.0'));
    }

    private function check(string $current, MockResponse $response): UpdateCheck
    {
        self::bootKernel();
        $releases = new GitHubReleases(new MockHttpClient($response, 'https://api.github.com/repos/nicolopozzato/firefunds/'));

        return new UpdateCheck($releases, self::getContainer()->get(SettingRepository::class), new MockClock('2026-10-30 12:00:00 UTC'), $current);
    }
}
