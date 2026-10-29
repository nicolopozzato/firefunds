<?php

namespace App\Entity;

use App\Repository\ApiTokenRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A personal access token for the API: another program acting as its user
 * (`Authorization: Bearer ff_…`). Only its hash is kept; the user sees the
 * token once. A scope limits what it may do; revoked, it stops working.
 */
#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
#[ORM\Table(name: 'api_token')]
#[ORM\UniqueConstraint(columns: ['token_hash'])]
class ApiToken
{
    public const PREFIX = 'ff_';
    /** Everything the user can do, but managing tokens, administration and push devices. */
    public const SCOPE_FULL = 'full';
    /** Drafts only (and reading accounts, categories and tags to fill them): a phone reading the bank's notifications. */
    public const SCOPE_DRAFTS = 'drafts';
    public const SCOPES = [self::SCOPE_FULL, self::SCOPE_DRAFTS];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 20)]
    private string $scope;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    /** The first characters, to tell tokens apart in the list ("ff_a1b2…"). */
    #[ORM\Column(length: 12)]
    private string $hint;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(User $user, string $name, string $scope, string $plainToken, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->name = $name;
        $this->scope = $scope;
        $this->tokenHash = self::hash($plainToken);
        $this->hint = substr($plainToken, 0, 10);
        $this->createdAt = $now;
    }

    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
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

    public function getScope(): string
    {
        return $this->scope;
    }

    public function getHint(): string
    {
        return $this->hint;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function revoke(\DateTimeImmutable $now): void
    {
        $this->revokedAt ??= $now;
    }
}
