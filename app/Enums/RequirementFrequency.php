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

    /**
     * How many times a year this frequency recurs — used to annualize a requirement's volume
     * into an estimated yearly figure (section 3.3's "annual procurement" field). One-time is
     * treated as a single non-recurring occurrence (multiplier 1), not annualized further.
     */
    public function annualMultiplier(): int
    {
        return match ($this) {
            self::OneTime => 1,
            self::Weekly => 52,
            self::Biweekly => 26,
            self::Monthly => 12,
            self::Quarterly => 4,
            self::Annual => 1,
        };
    }
}
