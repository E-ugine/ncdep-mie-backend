<?php

namespace App\Enums;

enum DealEventType: string
{
    case Created = 'created';
    case StageTransition = 'stage_transition';
}
