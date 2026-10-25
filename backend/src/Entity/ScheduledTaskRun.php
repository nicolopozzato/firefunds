<?php

namespace App\Entity;

use App\Repository\ScheduledTaskRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One run of a scheduled task (App\Task): when, what started it, how it went.
 * `details` is what the task reports (counts, the symbols or currencies it
 * couldn't fetch), for the administrator's page to word in their language;
 * `error` is the technical message of a failure, for logs. A run with
 * nothing to do that ends like the one before it (the hourly backup check, not
 * due yet) takes that one's place (TaskRunLog::compact()): `repeats` says how
 * many times in a row, `firstStartedAt` since when. A system record, not
 * anybody's data, deleted after a while (TaskRunLog::prune()).
 */
#[ORM\Entity(repositoryClass: ScheduledTaskRunRepository::class)]
#[ORM\Table(name: 'scheduled_task_run')]
#[ORM\Index(name: 'idx_scheduled_task_run_task', columns: ['task', 'started_at'])]
class ScheduledTaskRun
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 40)]
    private string $task;

    #[ORM\Column(name: 'triggered_by', length: 10, enumType: TaskTrigger::class)]
    private TaskTrigger $trigger;

    #[ORM\Column(length: 10, enumType: TaskRunStatus::class)]
    private TaskRunStatus $status = TaskRunStatus::Running;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $details = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    /** Nothing to do (TaskResult::$idle): a run like it right after replaces it. */
    #[ORM\Column(options: ['default' => false])]
    private bool $idle = false;

    #[ORM\Column(options: ['default' => 1])]
    private int $repeats = 1;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $firstStartedAt = null;

    public function __construct(string $task, TaskTrigger $trigger, \DateTimeImmutable $startedAt)
    {
        $this->id = Uuid::v7();
        $this->task = $task;
        $this->trigger = $trigger;
        $this->startedAt = $startedAt;
    }

    /** @param array<string, mixed> $details */
    public function finish(TaskRunStatus $status, array $details, ?string $error, bool $idle, \DateTimeImmutable $finishedAt): void
    {
        $this->idle = $idle;
        $this->status = $status;
        $this->details = $details;
        $this->error = $error !== null ? mb_substr($error, 0, 2000) : null;
        $this->finishedAt = $finishedAt;
    }

    /** Whether this run ended the same way as an earlier one with nothing to do, so it can stand for both. */
    public function repeats(self $earlier): bool
    {
        return $this->idle && $earlier->idle
            && $this->task === $earlier->task
            && $this->status === $earlier->status
            && $this->details == $earlier->details;
    }

    /** Takes the place of an earlier run like it: counted, and dated from its start. */
    public function absorb(self $earlier): void
    {
        $this->repeats += $earlier->repeats;
        $this->firstStartedAt = $earlier->firstStartedAt ?? $earlier->startedAt;
    }

    public function getRepeats(): int
    {
        return $this->repeats;
    }

    public function getFirstStartedAt(): ?\DateTimeImmutable
    {
        return $this->firstStartedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTask(): string
    {
        return $this->task;
    }

    public function getTrigger(): TaskTrigger
    {
        return $this->trigger;
    }

    public function getStatus(): TaskRunStatus
    {
        return $this->status;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }

    public function getError(): ?string
    {
        return $this->error;
    }
}
