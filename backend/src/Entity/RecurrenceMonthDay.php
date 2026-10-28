<?php

namespace App\Entity;

/**
 * Which day of the month a monthly or yearly recurrence falls on, read off
 * its start date as calendars do: the 8th, the last day, the second Tuesday,
 * the last Tuesday.
 */
enum RecurrenceMonthDay: string
{
    /** The start date's day (the 31st is the last day in shorter months). */
    case Day = 'day';
    case LastDay = 'last_day';
    /** The start date's weekday, as many weeks in (the second Tuesday). */
    case Weekday = 'weekday';
    /** The last of the start date's weekday (the last Tuesday). */
    case LastWeekday = 'last_weekday';
}
