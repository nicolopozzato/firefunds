<?php

namespace App\Currency\Service;

/** Whether the figures that convert a currency can be trusted. */
enum RateStatus: string
{
    case Ok = 'ok';
    /** No rate at all, neither shared nor the user's: sums count its amounts as they are, holdings in it have no value. */
    case Missing = 'missing';
    /** The shared rates stopped days ago (the fetch keeps failing): the last one is carried over. */
    case Stale = 'stale';
}
