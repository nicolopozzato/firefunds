<?php

namespace App\Backup;

/** What the administrator chose for the automatic backups. */
final readonly class BackupSettings
{
    /** Hours between two backups. */
    public const INTERVALS = [6, 12, 24, 48, 168];
    public const MAX_RETENTION_DAYS = 365;

    public function __construct(
        public bool $enabled = true,
        public int $intervalHours = 24,
        public int $retentionDays = 14,
    ) {
    }
}
