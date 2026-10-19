<?php

namespace App\Entity;

use App\Repository\InvestmentPriceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One closing price per instrument per day, shared across every user who holds
 * that instrument (fetched once, regardless of how many users own it).
 */
#[ORM\Entity(repositoryClass: InvestmentPriceRepository::class)]
#[ORM\Table(name: 'investment_price')]
#[ORM\UniqueConstraint(columns: ['instrument_id', 'date'])]
#[ORM\HasLifecycleCallbacks]
class InvestmentPrice
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: InvestmentInstrument::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private InvestmentInstrument $instrument;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    /**
     * Editable by the user; starts out equal to originalPrice.
     */
    #[ORM\Column(type: 'decimal', precision: 19, scale: 4)]
    private string $price;

    /**
     * Preserves the value fetched from the price provider, even if the user
     * later edits `price`.
     */
    #[ORM\Column(type: 'decimal', precision: 19, scale: 4)]
    private string $originalPrice;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(InvestmentInstrument $instrument, \DateTimeImmutable $date, string $price)
    {
        $this->id = Uuid::v7();
        $this->instrument = $instrument;
        $this->date = $date;
        $this->price = $price;
        $this->originalPrice = $price;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getInstrument(): InvestmentInstrument
    {
        return $this->instrument;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): static
    {
        $this->price = $price;
        return $this;
    }

    public function getOriginalPrice(): string
    {
        return $this->originalPrice;
    }

    /**
     * Whether the user has overridden the provider's value for this day.
     */
    public function isUserEdited(): bool
    {
        return bccomp($this->price, $this->originalPrice, 4) !== 0;
    }

    /**
     * Replaces a provider-sourced value with a newer one from the same
     * provider (e.g. a forward-filled day whose real close arrived later).
     * A user-edited price is left untouched.
     *
     * @return bool whether anything changed
     */
    public function refreshFromProvider(string $price): bool
    {
        if ($this->isUserEdited() || bccomp($this->originalPrice, $price, 4) === 0) {
            return false;
        }

        $this->price = $price;
        $this->originalPrice = $price;

        return true;
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
