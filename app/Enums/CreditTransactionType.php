<?php

namespace App\Enums;

enum CreditTransactionType: string
{
    case SignupBonus = 'signup_bonus';
    case TopUp = 'top_up';
    case Charge = 'charge';
}
