<?php

namespace App\Entity;

use App\Repository\DraftRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Something that came from outside (the API, a phone reading the bank's
 * notifications) and may be a transaction: what is known of it, for the user
 * to review ("Da rivedere"). Accepted, it becomes one or more transactions;
 * matched, it was one already there or a recurring transaction expected that
 * day; discarded, it was nothing. Never deleted: closed.
 */
#[ORM\Entity(repositoryClass: DraftRepository::class)]
#[ORM\Table(name: 'draft')]
#[ORM\Index(columns: ['user_id', 'status'])]
class Draft
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_MATCHED = 'matched';
    public const STATUS_DISCARDED = 'discarded';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Who sent it: "api", "android"… */
    #[ORM\Column(length: 20)]
    private string $source;

    /** Which one: the API token's name, the bank's app. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $sourceName;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description;

    #[ORM\Column(type: 'decimal', precision: 19, scale: 4)]
    private string $amount;

    /** Money out of the user's account, into it, or not known. */
    #[ORM\Column(length: 3, nullable: true)]
    private ?string $direction;

    /** The clock time where it happened, as a transaction's (OccursLocally). */
    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 64)]
    private string $timezone;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'source_account_id', nullable: true, onDelete: 'SET NULL')]
    private ?Account $sourceAccount;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'destination_account_id', nullable: true, onDelete: 'SET NULL')]
    private ?Account $destinationAccount;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes;

    /** What arrived as it arrived (a notification's text): shown while reviewing. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $rawText;

    #[ORM\Column(length: 12)]
    private string $status = self::STATUS_PENDING;

    /** An identical transaction already there: the user says whether it is this one. */
    #[ORM\ManyToOne(targetEntity: Transaction::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Transaction $possibleDuplicate = null;

    /** @var list<string> the transactions it became or was matched to */
    #[ORM\Column(type: 'json')]
    private array $transactionIds = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    public function __construct(
        User $user,
        string $source,
        ?string $sourceName,
        string $amount,
        \DateTimeImmutable $occurredAt,
        string $timezone,
        ?string $description = null,
        ?string $direction = null,
        ?Account $sourceAccount = null,
        ?Account $destinationAccount = null,
        ?string $notes = null,
        ?string $rawText = null,
    ) {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->source = $source;
        $this->sourceName = $sourceName;
        $this->amount = $amount;
        $this->occurredAt = $occurredAt;
        $this->timezone = $timezone;
        $this->description = $description;
        $this->direction = $direction;
        $this->sourceAccount = $sourceAccount;
        $this->destinationAccount = $destinationAccount;
        $this->notes = $notes;
        $this->rawText = $rawText;
        $this->createdAt = new \DateTimeImmutable();
    }

    /** The user's own account it is on, when known: the asset side. */
    public function getAssetAccount(): ?Account
    {
        foreach ([$this->sourceAccount, $this->destinationAccount] as $account) {
            if ($account?->getType() === AccountType::Asset) {
                return $account;
            }
        }

        return null;
    }

    /** "out", "in", or what the accounts say. */
    public function effectiveDirection(): ?string
    {
        if ($this->direction !== null) {
            return $this->direction;
        }
        if ($this->sourceAccount?->getType() === AccountType::Asset && $this->destinationAccount?->getType() !== AccountType::Asset) {
            return 'out';
        }
        if ($this->destinationAccount?->getType() === AccountType::Asset && $this->sourceAccount?->getType() !== AccountType::Asset) {
            return 'in';
        }

        return null;
    }

    /** @param list<string> $transactionIds */
    public function close(string $status, array $transactionIds): void
    {
        $this->status = $status;
        $this->transactionIds = $transactionIds;
        $this->closedAt = new \DateTimeImmutable();
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getSourceName(): ?string
    {
        return $this->sourceName;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getDirection(): ?string
    {
        return $this->direction;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function getSourceAccount(): ?Account
    {
        return $this->sourceAccount;
    }

    public function getDestinationAccount(): ?Account
    {
        return $this->destinationAccount;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getRawText(): ?string
    {
        return $this->rawText;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getPossibleDuplicate(): ?Transaction
    {
        return $this->possibleDuplicate;
    }

    public function setPossibleDuplicate(?Transaction $transaction): void
    {
        $this->possibleDuplicate = $transaction;
    }

    /** @return list<string> */
    public function getTransactionIds(): array
    {
        return $this->transactionIds;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }
}
