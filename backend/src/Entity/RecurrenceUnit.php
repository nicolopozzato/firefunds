<?php

namespace App\Entity;

/** How often a recurring transaction comes back: every `interval` of these. */
enum RecurrenceUnit: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';
}
