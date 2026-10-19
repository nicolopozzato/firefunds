<?php

namespace App\Service;

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

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->settingRepository->getValue($key, (string) $default);
    }

    public function set(string $key, string $value): void
    {
        $this->settingRepository->set($key, $value);
    }
}
