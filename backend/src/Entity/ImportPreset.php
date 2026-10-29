<?php

namespace App\Entity;

use App\Repository\ImportPresetRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * How a bank's CSV file reads (separator, columns, date and number format)
 * and which account it is for, saved to import the next one in a click. The
 * app reads the file in the browser: `settings` is its own, kept as it sends
 * it; `headers` (the file's first row) recognises the bank's next file.
 */
#[ORM\Entity(repositoryClass: ImportPresetRepository::class)]
#[ORM\Table(name: 'import_preset')]
#[ORM\Index(columns: ['user_id'])]
class ImportPreset implements SoftDeletable
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Account $account;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $settings;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $headers;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    /**
     * @param array<string, mixed> $settings
     * @param list<string>         $headers
     */
    public function __construct(User $user, string $name, ?Account $account, array $settings, array $headers)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->name = $name;
        $this->account = $account;
        $this->settings = $settings;
        $this->headers = $headers;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @param array<string, mixed> $settings
     * @param list<string>         $headers
     */
    public function update(string $name, ?Account $account, array $settings, array $headers): void
    {
        $this->name = $name;
        $this->account = $account;
        $this->settings = $settings;
        $this->headers = $headers;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    /** @return array<string, mixed> */
    public function getSettings(): array
    {
        return $this->settings;
    }

    /** @return list<string> */
    public function getHeaders(): array
    {
        return $this->headers;
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
}
