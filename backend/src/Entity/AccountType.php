<?php

namespace App\Entity;

enum AccountType: string
{
    case Asset = 'asset';
    case Expense = 'expense';
    case Revenue = 'revenue';
}
