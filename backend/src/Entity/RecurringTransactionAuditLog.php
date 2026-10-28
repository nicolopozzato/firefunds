<?php

namespace App\Entity;

use App\Repository\RecurringTransactionAuditLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One entry of a recurring transaction's history: created, updated (what
 * changed: the template, the schedule, the planned amounts, paused or not)
 * or deleted.
 */
#[ORM\Entity(repositoryClass: RecurringTransactionAuditLogRepository::class)]
#[ORM\Table(name: 'recurring_transaction_audit_log')]
#[ORM\Index(columns: ['recurring_id', 'created_at'])]
class RecurringTransactionAuditLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: RecurringTransaction::class)]
    #[ORM\JoinColumn(name: 'recurring_id', nullable: false, onDelete: 'CASCADE')]
    private RecurringTransaction $recurring;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20)]
    private string $action;

    /**
     * @var array<int, array{field: string, oldValue: mixed, newValue: mixed}>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $changes;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<int, array{field: string, oldValue: mixed, newValue: mixed}>|null $changes
     */
    public function __construct(RecurringTransaction $recurring, User $user, string $action, ?array $changes = null)
    {
        $this->id = Uuid::v7();
        $this->recurring = $recurring;
        $this->user = $user;
        $this->action = $action;
        $this->changes = $changes;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRecurring(): RecurringTransaction
    {
        return $this->recurring;
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
