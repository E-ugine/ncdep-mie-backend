<?php

namespace App\Enums;

enum BuyerRequirementStatus: string
{
    case Open = 'open';
    case PartiallyMatched = 'partially_matched';
    case Fulfilled = 'fulfilled';
    case Expired = 'expired';
}
