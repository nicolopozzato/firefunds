<?php

namespace App\Entity;

/**
 * What happens on a recurrence's day: the transaction is created by itself,
 * or the user enters it (an amount that changes, like a bill): one like it
 * arriving around that day is recognised, else the user is reminded.
 */
enum RecurrenceMode: string
{
    case Auto = 'auto';
    /** "I'll enter it" (the value predates the name). */
    case Confirm = 'confirm';
}
