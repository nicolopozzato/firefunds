<?php

namespace App\Admin\DTO;

use App\Backup\BackupSettings;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class BackupSettingsRequest
{
    public function __construct(
        public bool $enabled,

        #[Assert\Choice(choices: BackupSettings::INTERVALS)]
        public int $intervalHours,

        #[Assert\Range(min: 1, max: BackupSettings::MAX_RETENTION_DAYS)]
        public int $retentionDays,
    ) {
    }
}
