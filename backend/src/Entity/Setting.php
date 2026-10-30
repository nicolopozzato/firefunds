<?php

namespace App\Entity;

use App\Repository\SettingRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SettingRepository::class)]
#[ORM\Table(name: 'setting')]
#[ORM\HasLifecycleCallbacks]
class Setting
{
    public const KEY_REGISTRATION_ENABLED = 'registration_enabled';
    public const KEY_APP_NAME = 'app_name';
    public const KEY_BACKUP_ENABLED = 'backup_enabled';
    public const KEY_BACKUP_INTERVAL_HOURS = 'backup_interval_hours';
    public const KEY_BACKUP_RETENTION_DAYS = 'backup_retention_days';
    /** Web Push: the server's own VAPID keys (made on first use) and the address it says it is. */
    public const KEY_PUSH_PUBLIC_KEY = 'push_public_key';
    public const KEY_PUSH_PRIVATE_KEY = 'push_private_key';
    public const KEY_PUSH_SUBJECT = 'push_subject';
    /** New versions on GitHub (UpdateCheck): whether to look, and what was found (JSON). */
    public const KEY_UPDATE_CHECK_ENABLED = 'update_check_enabled';
    public const KEY_LATEST_RELEASE = 'latest_release';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 100, unique: true)]
    private string $key;

    #[ORM\Column(type: 'text')]
    private string $value;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $key, string $value)
    {
        $this->id = Uuid::v7();
        $this->key = $key;
        $this->value = $value;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): static
    {
        $this->value = $value;
        return $this;
    }

    public function getBoolValue(): bool
    {
        return filter_var($this->value, FILTER_VALIDATE_BOOLEAN);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
