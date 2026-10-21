<?php

namespace App\Service;

use App\Backup\BackupSettings;
use App\Entity\Setting;
use App\Repository\SettingRepository;

class SettingsService
{
    public function __construct(
        private SettingRepository $settingRepository,
    ) {
    }

    public function isRegistrationEnabled(): bool
    {
        return $this->settingRepository->getBoolValue('registration_enabled', true);
    }

    public function disableRegistration(): void
    {
        $this->settingRepository->set('registration_enabled', 'false');
    }

    public function enableRegistration(): void
    {
        $this->settingRepository->set('registration_enabled', 'true');
    }

    public function getBackup(): BackupSettings
    {
        $defaults = new BackupSettings();

        return new BackupSettings(
            $this->settingRepository->getBoolValue(Setting::KEY_BACKUP_ENABLED, $defaults->enabled),
            (int) $this->settingRepository->getValue(Setting::KEY_BACKUP_INTERVAL_HOURS, (string) $defaults->intervalHours),
            (int) $this->settingRepository->getValue(Setting::KEY_BACKUP_RETENTION_DAYS, (string) $defaults->retentionDays),
        );
    }

    public function saveBackup(BackupSettings $settings): void
    {
        $this->settingRepository->set(Setting::KEY_BACKUP_ENABLED, $settings->enabled ? 'true' : 'false');
        $this->settingRepository->set(Setting::KEY_BACKUP_INTERVAL_HOURS, (string) $settings->intervalHours);
        $this->settingRepository->set(Setting::KEY_BACKUP_RETENTION_DAYS, (string) $settings->retentionDays);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->settingRepository->getValue($key, (string) $default);
    }

    public function set(string $key, string $value): void
    {
        $this->settingRepository->set($key, $value);
    }
}
