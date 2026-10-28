<?php

namespace App\Entity;

use App\Repository\NotificationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Something the app tells a user (the bell): a type the app words itself
 * (`params` fill its text, never sentences from here), and a key, so the same
 * condition raised again updates the notification instead of adding one.
 * Open until resolved, by the system (the cause is gone) or by what the user
 * did about it; dismissing only hides it.
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notification')]
#[ORM\Index(columns: ['user_id', 'resolved_at'])]
class Notification
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 40)]
    private string $type;

    /** The same condition, e.g. "task:prices" or "recurring:<id>:<day>". */
    #[ORM\Column(name: 'dedupe_key', length: 120)]
    private string $key;

    /** @var array<string, string|int|float|null> */
    #[ORM\Column(type: 'json')]
    private array $params;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dismissedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    /** Sent to the user's devices (or left out of push by their choice): never twice. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $pushedAt = null;

    /** @param array<string, string|int|float|null> $params */
    public function __construct(User $user, string $type, string $key, array $params, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->type = $type;
        $this->key = $key;
        $this->params = $params;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /** @return array<string, string|int|float|null> */
    public function getParams(): array
    {
        return $this->params;
    }

    /** @param array<string, string|int|float|null> $params */
    public function update(array $params, \DateTimeImmutable $now): void
    {
        if ($params !== $this->params) {
            $this->params = $params;
            $this->updatedAt = $now;
        }
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function markRead(\DateTimeImmutable $now): void
    {
        $this->readAt ??= $now;
    }

    public function dismiss(\DateTimeImmutable $now): void
    {
        $this->dismissedAt ??= $now;
        $this->markRead($now);
    }

    public function isDismissed(): bool
    {
        return $this->dismissedAt !== null;
    }

    public function isPushed(): bool
    {
        return $this->pushedAt !== null;
    }

    public function markPushed(\DateTimeImmutable $now): void
    {
        $this->pushedAt ??= $now;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function resolve(\DateTimeImmutable $now): void
    {
        $this->resolvedAt ??= $now;
    }
}
