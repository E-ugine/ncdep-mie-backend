<?php

namespace App\Enums;

enum BuyerVerificationStatus: string
{
    case Unverified = 'unverified';
    case Pending = 'pending';
    case Verified = 'verified';
}
