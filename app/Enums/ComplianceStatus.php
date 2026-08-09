<?php

namespace App\Enums;

enum ComplianceStatus: string
{
    case Pending = 'pending';
    case Compliant = 'compliant';
    case NonCompliant = 'non_compliant';
}
