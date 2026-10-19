<?php

namespace App\Entity;

use App\Repository\InvestmentTransactionAuditLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: InvestmentTransactionAuditLogRepository::class)]
#[ORM\Table(name: 'investment_transaction_audit_log')]
#[ORM\Index(columns: ['investment_transaction_id', 'created_at'])]
class InvestmentTransactionAuditLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: InvestmentTransaction::class)]
    #[ORM\JoinColumn(name: 'investment_transaction_id', nullable: false, onDelete: 'CASCADE')]
    private InvestmentTransaction $investmentTransaction;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20)]
    private string $action;

    /**
     * @var array<int, array{field: string, oldValue: mixed, newValue: mixed}>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $changes = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<int, array{field: string, oldValue: mixed, newValue: mixed}>|null $changes
     */
    public function __construct(InvestmentTransaction $investmentTransaction, User $user, string $action, ?array $changes = null)
    {
        $this->id = Uuid::v7();
        $this->investmentTransaction = $investmentTransaction;
        $this->user = $user;
        $this->action = $action;
        $this->changes = $changes;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getInvestmentTransaction(): InvestmentTransaction
    {
        return $this->investmentTransaction;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * @return array<int, array{field: string, oldValue: mixed, newValue: mixed}>|null
     */
    public function getChanges(): ?array
    {
        return $this->changes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
