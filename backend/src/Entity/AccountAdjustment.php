<?php

namespace App\Entity;

use App\Repository\AccountAdjustmentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An "other" change to an asset account's balance on a day, besides its
 * opening balance: money that was already there, or a correction, rather
 * than income or an expense. Managed from the account's edit form, never
 * listed as a transaction and never counted in income and expenses; the net
 * worth report shows it as "other". An account's opening balance carried over
 * by a deletion becomes one too (AccountDeletionService).
 */
#[ORM\Entity(repositoryClass: AccountAdjustmentRepository::class)]
#[ORM\Table(name: 'account_adjustment')]
#[ORM\Index(columns: ['account_id'])]
#[ORM\HasLifecycleCallbacks]
class AccountAdjustment implements SoftDeletable
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Account $account;

    /** A calendar day, like the opening balance date: the balance changes from that day on. */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    /** Signed: positive adds to the balance, negative takes from it. */
    #[ORM\Column(type: 'decimal', precision: 19, scale: 4)]
    private string $amount;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct(Account $account, \DateTimeImmutable $date, string $amount, ?string $description = null)
    {
        $this->id = Uuid::v7();
        $this->account = $account;
        $this->date = $date;
        $this->amount = $amount;
        $this->description = $description;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
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
