<?php

namespace App\Tests\Unit\Backup;

use App\Backup\BackupManifest;
use App\Backup\RestoreCompatibility;
use PHPUnit\Framework\TestCase;

class RestoreCompatibilityTest extends TestCase
{
    private const KNOWN = ['M1', 'M2', 'M3'];

    public function testABackupAtTheLatestMigrationIsTheSameVersion(): void
    {
        $this->assertSame(RestoreCompatibility::Same, RestoreCompatibility::of('M3', self::KNOWN));
    }

    public function testABackupBeforeTheLatestMigrationIsOlder(): void
    {
        $this->assertSame(RestoreCompatibility::Older, RestoreCompatibility::of('M1', self::KNOWN));
        $this->assertSame(RestoreCompatibility::Older, RestoreCompatibility::of(null, self::KNOWN), 'an empty database');
    }

    public function testABackupWithAMigrationTheCodeDoesNotKnowIsNewer(): void
    {
        $this->assertSame(RestoreCompatibility::Newer, RestoreCompatibility::of('M4', self::KNOWN));
    }

    public function testTheManifestSurvivesItsOwnFormatAndRejectsOthers(): void
    {
        $manifest = new BackupManifest('0.3.0', '2026-10-21T03:05:00+00:00', 'M3', 'scheduled');

        $this->assertEquals($manifest, BackupManifest::fromArray($manifest->toArray()));
        $this->assertNull(BackupManifest::fromArray([...$manifest->toArray(), 'format' => 2]));
        $this->assertNull(BackupManifest::fromArray(['format' => 1]));
    }
}
