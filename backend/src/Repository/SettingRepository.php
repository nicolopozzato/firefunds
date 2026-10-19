<?php

namespace App\Repository;

use App\Entity\Setting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Setting>
 */
class SettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Setting::class);
    }

    public function get(string $key): ?Setting
    {
        return $this->findOneBy(['key' => $key]);
    }

    public function getValue(string $key, string $default = ''): string
    {
        $setting = $this->get($key);
        return $setting?->getValue() ?? $default;
    }

    public function getBoolValue(string $key, bool $default = false): bool
    {
        $setting = $this->get($key);
        return $setting?->getBoolValue() ?? $default;
    }

    public function set(string $key, string $value): Setting
    {
        $setting = $this->get($key);

        if ($setting === null) {
            $setting = new Setting($key, $value);
        } else {
            $setting->setValue($value);
        }

        $this->getEntityManager()->persist($setting);
        $this->getEntityManager()->flush();

        return $setting;
    }

    public function isRegistrationEnabled(): bool
    {
        return $this->getBoolValue(Setting::KEY_REGISTRATION_ENABLED, true);
    }

    public function setRegistrationEnabled(bool $enabled): void
    {
        $this->set(Setting::KEY_REGISTRATION_ENABLED, $enabled ? 'true' : 'false');
    }
}
