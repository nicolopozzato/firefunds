<?php

namespace App\Backup;

/**
 * What a backup says about itself (manifest.json inside the archive), so a
 * restore can tell whether it fits this installation before touching anything.
 */
final readonly class BackupManifest
{
    public const FORMAT = 1;

    public function __construct(
        /** The FireFunds version that made it. */
        public string $appVersion,
        /** UTC instant, ISO 8601. */
        public string $createdAt,
        /** The newest migration the database had (its class name), null for an empty database. */
        public ?string $latestMigration,
        /** BackupService::TRIGGER_*. */
        public string $trigger,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'appVersion' => $this->appVersion,
            'createdAt' => $this->createdAt,
            'latestMigration' => $this->latestMigration,
            'trigger' => $this->trigger,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        if (self::FORMAT !== ($data['format'] ?? null)
            || !\is_string($data['appVersion'] ?? null)
            || !\is_string($data['createdAt'] ?? null)
            || !\is_string($data['trigger'] ?? null)
            || !(null === ($data['latestMigration'] ?? null) || \is_string($data['latestMigration']))
        ) {
            return null;
        }

        return new self($data['appVersion'], $data['createdAt'], $data['latestMigration'] ?? null, $data['trigger']);
    }
}
