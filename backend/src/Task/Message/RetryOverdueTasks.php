<?php

namespace App\Task\Message;

/** Every 15 minutes: runs again the tasks whose last scheduled run failed or never happened. */
final readonly class RetryOverdueTasks
{
}
