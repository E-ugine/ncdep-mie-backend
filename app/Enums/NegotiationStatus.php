<?php

namespace App\Enums;

enum NegotiationStatus: string
{
    case Open = 'open';
    case Countered = 'countered';
    case Agreed = 'agreed';
    case Failed = 'failed';
}
