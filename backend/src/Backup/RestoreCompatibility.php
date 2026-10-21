<?php

namespace App\Backup;

/**
 * How a backup's database compares with the code that is running: the same
 * schema, an older one (restoring brings it up to date with the migrations),
 * or a newer one (it was made by a later version, which has to be installed
 * first).
 */
enum RestoreCompatibility
{
    case Same;
    case Older;
    case Newer;

    /**
     * @param list<string> $knownMigrations every migration the code has, oldest first
     */
    public static function of(?string $backupLatestMigration, array $knownMigrations): self
    {
        if (null === $backupLatestMigration) {
            return self::Older;
        }
        if (!\in_array($backupLatestMigration, $knownMigrations, true)) {
            return self::Newer;
        }

        return $backupLatestMigration === end($knownMigrations) ? self::Same : self::Older;
    }
}
