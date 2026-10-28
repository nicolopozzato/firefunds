<?php

namespace App\Entity;

/** A recurrence falling on a Saturday or Sunday: as it is, or moved to the working day before or after (salaries, direct debits). */
enum WeekendShift: string
{
    case None = 'none';
    case Before = 'before';
    case After = 'after';
}
