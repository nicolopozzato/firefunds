<?php

namespace App\Backup;

final readonly class RestoreCheck
{
    public function __construct(
        public BackupManifest $manifest,
        public RestoreCompatibility $compatibility,
        public string $currentVersion,
    ) {
    }
}
