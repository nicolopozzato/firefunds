<?php

namespace App\Entity;

use App\Repository\AccountAuditLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One entry of an account's history: created, updated (with what changed:
 * its fields, and each other balance change added, changed or removed) or
 * deleted. Changing the opening balance or an adjustment rewrites every past
 * balance, so this is what tells why they moved.
 */
#[ORM\Entity(repositoryClass: AccountAuditLogRepository::class)]
#[ORM\Table(name: 'account_audit_log')]
#[ORM\Index(columns: ['account_id', 'created_at'])]
class AccountAuditLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Account $account;

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
    public function __construct(Account $account, User $user, string $action, ?array $changes = null)
    {
        $this->id = Uuid::v7();
        $this->account = $account;
        $this->user = $user;
        $this->action = $action;
        $this->changes = $changes;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAccount(): Account
    {
        return $this->account;
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
