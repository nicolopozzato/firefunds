<?php

namespace App\Admin\DTO;

use App\Backup\BackupFile;
use App\Backup\BackupService;
use App\Backup\BackupSettings;

final readonly class BackupsResponse
{
    /**
     * @param list<array{name: string, size: int, createdAt: ?string, appVersion: ?string, trigger: ?string}> $backups
     */
    public function __construct(
        public bool $enabled,
        public int $intervalHours,
        public int $retentionDays,
        public string $appVersion,
        /** False when the backups directory isn't a volume: they would be lost with the container. */
        public bool $onVolume,
        public array $backups,
    ) {
    }

    /**
     * @param list<BackupFile> $files
     */
    public static function of(BackupSettings $settings, BackupService $service, array $files): self
    {
        return new self(
            $settings->enabled,
            $settings->intervalHours,
            $settings->retentionDays,
            $service->getAppVersion(),
            $service->isOnVolume(),
            array_map(self::item(...), $files),
        );
    }

    /**
     * @return array{name: string, size: int, createdAt: ?string, appVersion: ?string, trigger: ?string}
     */
    public static function item(BackupFile $file): array
    {
        return [
            'name' => $file->name,
            'size' => $file->size,
            'createdAt' => $file->manifest?->createdAt,
            'appVersion' => $file->manifest?->appVersion,
            'trigger' => $file->manifest?->trigger,
        ];
    }
}
