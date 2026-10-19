<?php

namespace App\Entity;

use App\Repository\InvestmentInstrumentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: InvestmentInstrumentRepository::class)]
#[ORM\Table(name: 'investment_instrument')]
#[ORM\Index(columns: ['user_id'])]
#[ORM\HasLifecycleCallbacks]
class InvestmentInstrument implements SoftDeletable
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Length(max: 20)]
    private ?string $ticker = null;

    /**
     * The exact symbol Yahoo Finance expects for this instrument, including
     * any exchange suffix (e.g. "VWCE.DE"). `ticker` is only ever what's
     * shown to the user; this is what price fetching uses under the hood.
     */
    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Length(max: 20)]
    private ?string $yahooTicker = null;

    #[ORM\Column(length: 12, nullable: true)]
    #[Assert\Length(max: 12)]
    private ?string $isin = null;

    /**
     * This instrument's own capital gains tax percentage, when it differs
     * from the user's (e.g. 15.46 for an ETF 78.08% in white-list bonds:
     * 26% on the rest, 12.5% on that share). Null = the user's rate.
     */
    #[ORM\Column(type: 'decimal', precision: 7, scale: 4, nullable: true)]
    private ?string $capitalGainsTaxRate = null;

    #[ORM\Column(length: 20, enumType: InvestmentInstrumentType::class)]
    private InvestmentInstrumentType $type;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct(User $user, string $name, InvestmentInstrumentType $type)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->name = $name;
        $this->type = $type;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getTicker(): ?string
    {
        return $this->ticker;
    }

    public function setTicker(?string $ticker): static
    {
        $this->ticker = $ticker;
        return $this;
    }

    public function getYahooTicker(): ?string
    {
        return $this->yahooTicker;
    }

    /** The symbol prices are asked for: the Yahoo ticker, else the ticker; null without either. */
    public function getQuoteSymbol(): ?string
    {
        $symbol = $this->yahooTicker ?: $this->ticker;

        return $symbol !== null && $symbol !== '' ? $symbol : null;
    }

    public function setYahooTicker(?string $yahooTicker): static
    {
        $this->yahooTicker = $yahooTicker;
        return $this;
    }

    public function getIsin(): ?string
    {
        return $this->isin;
    }

    public function setIsin(?string $isin): static
    {
        $this->isin = $isin;
        return $this;
    }

    public function getCapitalGainsTaxRate(): ?string
    {
        return $this->capitalGainsTaxRate;
    }

    public function setCapitalGainsTaxRate(?string $capitalGainsTaxRate): static
    {
        $this->capitalGainsTaxRate = $capitalGainsTaxRate;
        return $this;
    }

    public function getType(): InvestmentInstrumentType
    {
        return $this->type;
    }

    public function setType(InvestmentInstrumentType $type): static
    {
        $this->type = $type;
        return $this;
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
