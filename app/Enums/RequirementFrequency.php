<?php

namespace App\Enums;

enum RequirementFrequency: string
{
    case OneTime = 'one_time';
    case Weekly = 'weekly';
    case Biweekly = 'biweekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Annual = 'annual';
}
