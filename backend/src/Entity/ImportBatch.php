<?php

namespace App\Entity;

use App\Repository\ImportBatchRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One import of a file: the transactions and balance changes it recorded
 * point to it (`Transaction::importBatch`, `AccountAdjustment::importBatch`),
 * and it remembers the accounts, categories and tags it created, so the whole
 * import can be undone. Undoing deletes what it recorded (soft) and what it
 * created that nothing else uses since; the batch stays, marked undone.
 */
#[ORM\Entity(repositoryClass: ImportBatchRepository::class)]
#[ORM\Table(name: 'import_batch')]
#[ORM\Index(columns: ['user_id', 'created_at'])]
class ImportBatch
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** The file's name, as the browser gave it. */
    #[ORM\Column(length: 255)]
    private string $fileName;

    #[ORM\Column]
    private int $transactionCount = 0;

    #[ORM\Column]
    private int $adjustmentCount = 0;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $createdAccountIds = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $createdCategoryIds = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $createdTagIds = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $undoneAt = null;

    public function __construct(User $user, string $fileName)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->fileName = mb_substr($fileName, 0, 255);
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

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function getTransactionCount(): int
    {
        return $this->transactionCount;
    }

    public function getAdjustmentCount(): int
    {
        return $this->adjustmentCount;
    }

    public function setCounts(int $transactions, int $adjustments): void
    {
        $this->transactionCount = $transactions;
        $this->adjustmentCount = $adjustments;
    }

    /** @return list<string> */
    public function getCreatedAccountIds(): array
    {
        return $this->createdAccountIds;
    }

    /** @return list<string> */
    public function getCreatedCategoryIds(): array
    {
        return $this->createdCategoryIds;
    }

    /** @return list<string> */
    public function getCreatedTagIds(): array
    {
        return $this->createdTagIds;
    }

    /**
     * @param list<string> $accounts
     * @param list<string> $categories
     * @param list<string> $tags
     */
    public function setCreated(array $accounts, array $categories, array $tags): void
    {
        $this->createdAccountIds = array_values(array_unique($accounts));
        $this->createdCategoryIds = array_values(array_unique($categories));
        $this->createdTagIds = array_values(array_unique($tags));
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUndoneAt(): ?\DateTimeImmutable
    {
        return $this->undoneAt;
    }

    public function markUndone(): void
    {
        $this->undoneAt = new \DateTimeImmutable();
    }
}
