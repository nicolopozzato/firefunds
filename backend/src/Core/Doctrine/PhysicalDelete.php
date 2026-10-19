<?php

namespace App\Core\Doctrine;

/**
 * Marks the only classes allowed to delete rows for good: nothing is ever
 * physically deleted otherwise (NoPhysicalDeleteTest checks it). Each one
 * runs only on the owner's explicit request, and says why here.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class PhysicalDelete
{
    public function __construct(public string $reason)
    {
    }
}
