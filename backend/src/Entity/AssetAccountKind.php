<?php

namespace App\Entity;

enum AssetAccountKind: string
{
    case Checking = 'checking';
    case Investment = 'investment';
}
