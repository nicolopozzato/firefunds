<?php

namespace App\Entity;

use App\Repository\ExchangeRateOverrideRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A rate the user entered or corrected for one currency and day (units of the
 * currency for 1 EUR, as ExchangeRate): theirs alone, and what the shared
 * fetch never touches. Taking it away (soft delete) gives the day its shared
 * rate back.
 */
#[ORM\Entity(repositoryClass: ExchangeRateOverrideRepository::class)]
#[ORM\Table(name: 'exchange_rate_override')]
#[ORM\UniqueConstraint(name: 'uniq_exchange_rate_override_day_active', columns: ['user_id', 'currency', 'date'], options: ['where' => '(deleted_at IS NULL)'])]
#[ORM\HasLifecycleCallbacks]
class ExchangeRateOverride implements SoftDeletable
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 8)]
    private string $rate;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct(User $user, string $currency, \DateTimeImmutable $date, string $rate)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->currency = strtoupper($currency);
        $this->date = $date;
        $this->rate = $rate;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getRate(): string
    {
        return $this->rate;
    }

    public function setRate(string $rate): static
    {
        $this->rate = $rate;

        return $this;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function markDeleted(): void
    {
        $this->deletedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
