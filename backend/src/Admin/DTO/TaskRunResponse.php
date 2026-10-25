<?php

namespace App\Admin\DTO;

use App\Entity\ScheduledTaskRun;
use App\Entity\TaskRunStatus;

final readonly class TaskRunResponse
{
    /** A run still "running" after this long was cut off (the process stopped): it never finished. */
    private const INTERRUPTED_AFTER = '3 hours';

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public string $id,
        /** schedule, retry or manual. */
        public string $trigger,
        /** running, success, partial, failed, or interrupted. */
        public string $status,
        public string $startedAt,
        public ?string $finishedAt,
        public array $details,
        public ?string $error,
        /** How many runs in a row ended like this one, with nothing to do; since firstStartedAt. */
        public int $repeats,
        public ?string $firstStartedAt,
    ) {
    }

    public static function of(ScheduledTaskRun $run, \DateTimeImmutable $now): self
    {
        $status = $run->getStatus() === TaskRunStatus::Running && $run->getStartedAt() < $now->modify('-'.self::INTERRUPTED_AFTER)
            ? 'interrupted'
            : $run->getStatus()->value;

        return new self(
            (string) $run->getId(),
            $run->getTrigger()->value,
            $status,
            $run->getStartedAt()->format(\DATE_ATOM),
            $run->getFinishedAt()?->format(\DATE_ATOM),
            $run->getDetails(),
            $run->getError(),
            $run->getRepeats(),
            $run->getFirstStartedAt()?->format(\DATE_ATOM),
        );
    }
}
