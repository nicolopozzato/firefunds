<?php

namespace App\Entity;

enum UserTheme: string
{
    case Light = 'light';
    case Dark = 'dark';
    case System = 'system';
}
