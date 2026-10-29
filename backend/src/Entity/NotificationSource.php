<?php

namespace App\Entity;

use App\Repository\NotificationSourceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An app on the user's phone whose notifications reach the server (the
 * bank's, Satispay…): made with its first notification. Which account its
 * payments are on, and how to read them (`rules`, tried in order before the
 * generic reading): see NotificationReading.
 */
#[ORM\Entity(repositoryClass: NotificationSourceRepository::class)]
#[ORM\Table(name: 'notification_source')]
#[ORM\UniqueConstraint(columns: ['user_id', 'package_name'])]
class NotificationSource
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 255)]
    private string $packageName;

    #[ORM\Column(length: 120)]
    private string $appName;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Account $account = null;

    /** @var list<array{kind: string, pattern: string}> kind: out, in or ignore */
    #[ORM\Column(type: 'json')]
    private array $rules = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $packageName, string $appName)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->packageName = $packageName;
        $this->appName = $appName;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getPackageName(): string
    {
        return $this->packageName;
    }

    public function getAppName(): string
    {
        return $this->appName;
    }

    public function setAppName(string $appName): void
    {
        $this->appName = $appName;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function setAccount(?Account $account): void
    {
        $this->account = $account;
    }

    /** @return list<array{kind: string, pattern: string}> */
    public function getRules(): array
    {
        return $this->rules;
    }

    /** @param list<array{kind: string, pattern: string}> $rules */
    public function setRules(array $rules): void
    {
        $this->rules = $rules;
    }
}
