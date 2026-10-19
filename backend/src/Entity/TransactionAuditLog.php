<?php

namespace App\Entity;

use App\Repository\TransactionAuditLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TransactionAuditLogRepository::class)]
#[ORM\Table(name: 'transaction_audit_log')]
#[ORM\Index(columns: ['transaction_id', 'created_at'])]
class TransactionAuditLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Transaction::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Transaction $transaction;

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
    public function __construct(Transaction $transaction, User $user, string $action, ?array $changes = null)
    {
        $this->id = Uuid::v7();
        $this->transaction = $transaction;
        $this->user = $user;
        $this->action = $action;
        $this->changes = $changes;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTransaction(): Transaction
    {
        return $this->transaction;
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
