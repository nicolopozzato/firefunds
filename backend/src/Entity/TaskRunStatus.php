<?php

namespace App\Entity;

/** How a scheduled task's run went. */
enum TaskRunStatus: string
{
    case Running = 'running';
    case Success = 'success';
    /** Done, but some of it could not be (a symbol the provider doesn't know): retrying wouldn't help. */
    case Partial = 'partial';
    /** Nothing done (an exception, every provider down): retried. */
    case Failed = 'failed';
}
