<?php

namespace App\Backup;

/** A backup archive on disk. The manifest is null when it can't be read (not one of ours, damaged). */
final readonly class BackupFile
{
    public function __construct(
        public string $name,
        public string $path,
        public int $size,
        public ?BackupManifest $manifest,
    ) {
    }
}
