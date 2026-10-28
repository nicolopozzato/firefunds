<?php

namespace App\Entity;

use App\Recurring\Schedule\RecurrenceRule;
use App\Repository\RecurringTransactionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A money transaction that comes back (a subscription, a bill, a salary, a
 * transfer to savings): a template, when it falls (RecurrenceRule) and what
 * happens then (RecurrenceMode). Everything on or before `handledThrough` is
 * dealt with (created, confirmed or skipped), so the next occurrence is the
 * first after it, and nothing is ever created twice.
 */
#[ORM\Entity(repositoryClass: RecurringTransactionRepository::class)]
#[ORM\Table(name: 'recurring_transaction')]
#[ORM\Index(columns: ['user_id'])]
#[ORM\HasLifecycleCallbacks]
class RecurringTransaction implements SoftDeletable
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'source_account_id', nullable: true, onDelete: 'RESTRICT')]
    private ?Account $sourceAccount;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'destination_account_id', nullable: true, onDelete: 'RESTRICT')]
    private ?Account $destinationAccount;

    #[ORM\Column(type: 'decimal', precision: 19, scale: 4)]
    private string $amount;

    /**
     * Planned changes of the amount, oldest first: from that day on, that
     * amount. Ones whose day has come are folded into `amount` on save.
     *
     * @var list<array{from: string, amount: string}>
     */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $amountChanges = [];

    /** A transfer between accounts in two currencies: what arrives. */
    #[ORM\Column(type: 'decimal', precision: 19, scale: 4, nullable: true)]
    private ?string $destinationAmount = null;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Category $category = null;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'recurring_transaction_tag')]
    private Collection $tags;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $startDate;

    #[ORM\Column(length: 10, enumType: RecurrenceUnit::class)]
    private RecurrenceUnit $unit = RecurrenceUnit::Month;

    #[ORM\Column(name: 'every_n', options: ['default' => 1])]
    private int $interval = 1;

    #[ORM\Column(length: 20, enumType: RecurrenceMonthDay::class, options: ['default' => 'day'])]
    private RecurrenceMonthDay $monthDay = RecurrenceMonthDay::Day;

    #[ORM\Column(length: 10, enumType: WeekendShift::class, options: ['default' => 'none'])]
    private WeekendShift $weekendShift = WeekendShift::None;

    /** @var list<int>|null 1-12, null for every month */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $months = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

    /** How many times in all, counted from the start date. */
    #[ORM\Column(name: 'occurrence_count', nullable: true)]
    private ?int $count = null;

    /** The clock time each transaction gets, "HH:MM", where it happens (`timezone`). */
    #[ORM\Column(length: 5)]
    private string $time = '12:00';

    #[ORM\Column(length: 64)]
    private string $timezone;

    #[ORM\Column(length: 10, enumType: RecurrenceMode::class, options: ['default' => 'auto'])]
    private RecurrenceMode $mode = RecurrenceMode::Auto;

    /** Entered by hand: days after its day before the user is reminded. */
    #[ORM\Column(options: ['default' => 3])]
    private int $reminderDays = 3;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $pausedAt = null;

    /** Every occurrence on or before this day is dealt with. */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $handledThrough;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct(User $user, string $description, \DateTimeImmutable $startDate, string $timezone, \DateTimeImmutable $handledThrough)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->description = $description;
        $this->startDate = $startDate;
        $this->timezone = $timezone;
        $this->handledThrough = $handledThrough;
        $this->sourceAccount = null;
        $this->destinationAccount = null;
        $this->amount = '0';
        $this->tags = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function rule(): RecurrenceRule
    {
        return new RecurrenceRule(
            start: $this->startDate,
            unit: $this->unit,
            interval: $this->interval,
            monthDay: $this->monthDay,
            weekendShift: $this->weekendShift,
            months: $this->months,
            end: $this->endDate,
            count: $this->count,
        );
    }

    /** The first occurrence not yet dealt with; null when there are no more. */
    public function nextOccurrence(): ?\DateTimeImmutable
    {
        return $this->rule()->nextAfter($this->handledThrough);
    }

    /** When an occurrence happens, where it happens: its day at the recurrence's time. */
    public function momentOn(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day->format('Y-m-d').' '.$this->time, new \DateTimeZone($this->timezone));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getSourceAccount(): ?Account
    {
        return $this->sourceAccount;
    }

    public function getDestinationAccount(): ?Account
    {
        return $this->destinationAccount;
    }

    public function setAccounts(?Account $source, ?Account $destination): static
    {
        $this->sourceAccount = $source;
        $this->destinationAccount = $destination;

        return $this;
    }

    /** The asset account it moves money on (a transfer's source): its currency is the amount's. */
    public function getAssetAccount(): Account
    {
        return $this->sourceAccount?->getType() === AccountType::Asset ? $this->sourceAccount : $this->destinationAccount;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    /** The amount of the occurrence on that day: the latest planned change by then, else the amount. */
    public function amountOn(\DateTimeImmutable $day): string
    {
        $amount = $this->amount;
        foreach ($this->amountChanges as $change) {
            if ($change['from'] <= $day->format('Y-m-d')) {
                $amount = $change['amount'];
            }
        }

        return $amount;
    }

    /** @return list<array{from: string, amount: string}> */
    public function getAmountChanges(): array
    {
        return $this->amountChanges;
    }

    /** @param list<array{from: string, amount: string}> $changes */
    public function setAmountChanges(array $changes): static
    {
        usort($changes, static fn (array $a, array $b) => $a['from'] <=> $b['from']);
        $this->amountChanges = array_values($changes);

        return $this;
    }

    public function getDestinationAmount(): ?string
    {
        return $this->destinationAmount;
    }

    public function setDestinationAmount(?string $destinationAmount): static
    {
        $this->destinationAmount = $destinationAmount;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    /** @return Collection<int, Tag> */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    /** @param list<Tag> $tags */
    public function setTags(array $tags): static
    {
        $this->tags->clear();
        foreach ($tags as $tag) {
            if (!$this->tags->contains($tag)) {
                $this->tags->add($tag);
            }
        }

        return $this;
    }

    public function removeTag(Tag $tag): static
    {
        $this->tags->removeElement($tag);

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    /**
     * @param list<int>|null $months
     */
    public function setSchedule(
        \DateTimeImmutable $startDate,
        RecurrenceUnit $unit,
        int $interval,
        RecurrenceMonthDay $monthDay,
        WeekendShift $weekendShift,
        ?array $months,
        ?\DateTimeImmutable $endDate,
        ?int $count,
        string $time,
    ): static {
        $this->startDate = $startDate;
        $this->unit = $unit;
        $this->interval = $interval;
        $this->monthDay = $monthDay;
        $this->weekendShift = $weekendShift;
        $this->months = $months;
        $this->endDate = $endDate;
        $this->count = $count;
        $this->time = $time;

        return $this;
    }

    public function getUnit(): RecurrenceUnit
    {
        return $this->unit;
    }

    public function getInterval(): int
    {
        return $this->interval;
    }

    public function getMonthDay(): RecurrenceMonthDay
    {
        return $this->monthDay;
    }

    public function getWeekendShift(): WeekendShift
    {
        return $this->weekendShift;
    }

    /** @return list<int>|null */
    public function getMonths(): ?array
    {
        return $this->months;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function getCount(): ?int
    {
        return $this->count;
    }

    public function getTime(): string
    {
        return $this->time;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function getMode(): RecurrenceMode
    {
        return $this->mode;
    }

    public function setMode(RecurrenceMode $mode): static
    {
        $this->mode = $mode;

        return $this;
    }

    public function getReminderDays(): int
    {
        return $this->reminderDays;
    }

    public function setReminderDays(int $days): static
    {
        $this->reminderDays = $days;

        return $this;
    }

    public function isPaused(): bool
    {
        return $this->pausedAt !== null;
    }

    public function pause(): void
    {
        $this->pausedAt ??= new \DateTimeImmutable();
    }

    /** What fell while paused is let go: it starts again from `today`. */
    public function resume(\DateTimeImmutable $today): void
    {
        $this->pausedAt = null;
        $this->handleThrough($today->modify('-1 day'));
    }

    public function getHandledThrough(): \DateTimeImmutable
    {
        return $this->handledThrough;
    }

    /** Never goes back: what is dealt with stays dealt with. */
    public function handleThrough(\DateTimeImmutable $day): void
    {
        $day = new \DateTimeImmutable($day->format('Y-m-d'));
        if ($day > $this->handledThrough) {
            $this->handledThrough = $day;
        }
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function markDeleted(): void
    {
        $this->deletedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
