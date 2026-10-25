<?php

namespace App\Task\Message;

/** The scheduler's turn for a task (src/Schedule.php). */
final readonly class RunScheduledTask
{
    public function __construct(public string $task)
    {
    }
}
