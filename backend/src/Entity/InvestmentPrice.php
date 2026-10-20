<?php

namespace App\Entity;

use App\Repository\InvestmentPriceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An instrument's price on one day (instruments, and so their prices, belong
 * to one user). Fetched from the provider for every calendar day, carried
 * over on days without a close; or entered by the user, one per day at most,
 * which the provider then never overwrites. A provider price the user
 * corrected keeps the provider's value in `originalPrice`, to restore it.
 * Only a manual price is ever deleted, and only softly.
 */
#[ORM\Entity(repositoryClass: InvestmentPriceRepository::class)]
#[ORM\Table(name: 'investment_price')]
#[ORM\UniqueConstraint(name: 'uniq_investment_price_day_active', columns: ['instrument_id', 'date'], options: ['where' => '(deleted_at IS NULL)'])]
#[ORM\HasLifecycleCallbacks]
class InvestmentPrice implements SoftDeletable
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: InvestmentInstrument::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private InvestmentInstrument $instrument;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    /** The price used everywhere: the provider's, or the user's. */
    #[ORM\Column(type: 'decimal', precision: 19, scale: 4)]
    private string $price;

    /** The provider's value for this day; null for a day only the user entered. */
    #[ORM\Column(type: 'decimal', precision: 19, scale: 4, nullable: true)]
    private ?string $originalPrice;

    #[ORM\Column(length: 10, enumType: InvestmentPriceSource::class, options: ['default' => 'market'])]
    private InvestmentPriceSource $source;

    /** What the provider said it was (market or filled), kept while the user's value replaces it. */
    #[ORM\Column(length: 10, enumType: InvestmentPriceSource::class, nullable: true)]
    private ?InvestmentPriceSource $originalSource;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct(
        InvestmentInstrument $instrument,
        \DateTimeImmutable $date,
        string $price,
        InvestmentPriceSource $source = InvestmentPriceSource::Market,
    ) {
        $this->id = Uuid::v7();
        $this->instrument = $instrument;
        $this->date = $date;
        $this->price = $price;
        $this->source = $source;
        $this->originalPrice = $source === InvestmentPriceSource::Manual ? null : $price;
        $this->originalSource = $source === InvestmentPriceSource::Manual ? null : $source;
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

    public function getOriginalPrice(): ?string
    {
        return $this->originalPrice;
    }

    public function getSource(): InvestmentPriceSource
    {
        return $this->source;
    }

    /** Entered or corrected by the user. */
    public function isManual(): bool
    {
        return $this->source === InvestmentPriceSource::Manual;
    }

    /** The user's value, from now on never overwritten by the provider. */
    public function setManualPrice(string $price): static
    {
        $this->price = $price;
        $this->source = InvestmentPriceSource::Manual;
        return $this;
    }

    /** Back to the provider's value. Only for a day the provider has one. */
    public function restoreProviderPrice(): static
    {
        if ($this->originalPrice === null || $this->originalSource === null) {
            throw new \LogicException('No provider price to restore.');
        }

        $this->price = $this->originalPrice;
        $this->source = $this->originalSource;
        return $this;
    }

    /**
     * Records a newer value from the provider (e.g. a carried-over day whose
     * real close arrived later). A manual price keeps the user's value: only
     * what would be restored changes.
     *
     * @return bool whether anything changed
     */
    public function refreshFromProvider(string $price, InvestmentPriceSource $source): bool
    {
        if ($this->originalPrice !== null && bccomp($this->originalPrice, $price, 4) === 0 && $this->originalSource === $source) {
            return false;
        }

        $this->originalPrice = $price;
        $this->originalSource = $source;
        if (!$this->isManual()) {
            $this->price = $price;
            $this->source = $source;
        }

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
