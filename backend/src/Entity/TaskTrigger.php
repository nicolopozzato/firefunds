<?php

namespace App\Entity;

/** What started a scheduled task's run. */
enum TaskTrigger: string
{
    case Schedule = 'schedule';
    /** The run due at the last scheduled time failed or never happened. */
    case Retry = 'retry';
    /** An administrator, from Administration → Scheduled tasks. */
    case Manual = 'manual';
}
