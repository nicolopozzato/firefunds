<?php

namespace App\Entity;

use App\Investment\Position\TradeCashEffect;
use App\Repository\InvestmentTransactionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: InvestmentTransactionRepository::class)]
#[ORM\Table(name: 'investment_transaction')]
#[ORM\Index(columns: ['user_id'])]
#[ORM\Index(columns: ['account_id', 'instrument_id'])]
#[ORM\HasLifecycleCallbacks]
class InvestmentTransaction implements SoftDeletable
{
    use OccursLocally;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_id', nullable: false, onDelete: 'RESTRICT')]
    private Account $account;

    #[ORM\ManyToOne(targetEntity: InvestmentInstrument::class)]
    #[ORM\JoinColumn(name: 'instrument_id', nullable: false, onDelete: 'RESTRICT')]
    private InvestmentInstrument $instrument;

    #[ORM\Column(length: 10, enumType: InvestmentTransactionType::class)]
    private InvestmentTransactionType $type;

    #[ORM\Column(type: 'decimal', precision: 20, scale: 6)]
    #[Assert\Positive]
    private string $quantity;

    #[ORM\Column(type: 'decimal', precision: 19, scale: 4)]
    #[Assert\Positive]
    private string $unitPrice;

    /**
     * What the trade was actually worth, as charged/credited by the broker,
     * fees and taxes excluded. Usually quantity x unit price, but the broker
     * rounds (e.g. a 50.00 monthly savings-plan buy of 0.300987 units at 166.12 = 49.99996):
     * this is the value the cash balance and the cost basis follow, while the
     * unit price is kept as quoted.
     */
    #[ORM\Column(type: 'decimal', precision: 19, scale: 4)]
    private string $amount;

    #[ORM\Column(type: 'decimal', precision: 19, scale: 4)]
    private string $fees = '0.0000';

    /** Taxes the broker withheld on this trade (e.g. capital gains tax on a SELL, Tobin tax on a BUY). */
    #[ORM\Column(type: 'decimal', precision: 19, scale: 4, options: ['default' => '0.0000'])]
    private string $taxes = '0.0000';


    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct(
        User $user,
        Account $account,
        InvestmentInstrument $instrument,
        InvestmentTransactionType $type,
        string $quantity,
        string $unitPrice,
        \DateTimeImmutable $occurredAt,
        ?string $amount = null,
    ) {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->account = $account;
        $this->instrument = $instrument;
        $this->type = $type;
        $this->quantity = $quantity;
        $this->unitPrice = $unitPrice;
        $this->amount = $amount ?? self::grossAmount($quantity, $unitPrice);
        $this->setOccurredAt($occurredAt);
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function setAccount(Account $account): static
    {
        $this->account = $account;
        return $this;
    }

    public function getInstrument(): InvestmentInstrument
    {
        return $this->instrument;
    }

    public function setInstrument(InvestmentInstrument $instrument): static
    {
        $this->instrument = $instrument;
        return $this;
    }

    public function getType(): InvestmentTransactionType
    {
        return $this->type;
    }

    public function setType(InvestmentTransactionType $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function setQuantity(string $quantity): static
    {
        $this->quantity = $quantity;
        return $this;
    }

    public function getUnitPrice(): string
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(string $unitPrice): static
    {
        $this->unitPrice = $unitPrice;
        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;
        return $this;
    }

    /** Quantity x unit price, the default amount of a trade. */
    public static function grossAmount(string $quantity, string $unitPrice): string
    {
        return bcmul($quantity, $unitPrice, 4);
    }

    public function getFees(): string
    {
        return $this->fees;
    }

    public function setFees(string $fees): static
    {
        $this->fees = $fees;
        return $this;
    }

    public function getTaxes(): string
    {
        return $this->taxes;
    }

    public function setTaxes(string $taxes): static
    {
        $this->taxes = $taxes;
        return $this;
    }

    /** Everything the trade cost on top of its amount: fees plus taxes. */
    public function getCosts(): string
    {
        return bcadd($this->fees, $this->taxes, 4);
    }

    /**
     * The trade's cash effect on the broker account, as a positive amount: a
     * BUY pays its amount plus costs, a SELL receives it minus costs.
     */
    public function getTotalAmount(): string
    {
        return TradeCashEffect::total($this->type->value, $this->amount, $this->getCosts());
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
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

    /** Undoes markDeleted(): only right after a delete (see RestoreWindow). */
    public function restore(): void
    {
        $this->deletedAt = null;
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
