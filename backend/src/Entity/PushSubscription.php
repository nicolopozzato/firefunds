<?php

namespace App\Entity;

use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One device (a browser, an installed app) that receives the user's push
 * notifications: the address its push service gave it and the keys to
 * encrypt for it. Like a session, not user data: removed when the user turns
 * it off or the push service says it no longer exists.
 */
#[ORM\Entity(repositoryClass: PushSubscriptionRepository::class)]
#[ORM\Table(name: 'push_subscription')]
#[ORM\UniqueConstraint(columns: ['endpoint'])]
class PushSubscription
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'text')]
    private string $endpoint;

    #[ORM\Column(length: 255)]
    private string $publicKey;

    #[ORM\Column(length: 255)]
    private string $authToken;

    /** What the user sees in their list: "Chrome su Android". */
    #[ORM\Column(length: 120)]
    private string $label;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSentAt = null;

    public function __construct(User $user, string $endpoint, string $publicKey, string $authToken, string $label, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->endpoint = $endpoint;
        $this->publicKey = $publicKey;
        $this->authToken = $authToken;
        $this->label = $label;
        $this->createdAt = $now;
    }

    /** The same device subscribing again (new keys, or another user logged in on it). */
    public function renew(User $user, string $publicKey, string $authToken, string $label): void
    {
        $this->user = $user;
        $this->publicKey = $publicKey;
        $this->authToken = $authToken;
        $this->label = $label;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function getAuthToken(): string
    {
        return $this->authToken;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastSentAt(): ?\DateTimeImmutable
    {
        return $this->lastSentAt;
    }

    public function markSent(\DateTimeImmutable $now): void
    {
        $this->lastSentAt = $now;
    }
}
