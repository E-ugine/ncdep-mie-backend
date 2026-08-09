<?php

namespace App\Enums;

enum ModuleAccessOutcome: string
{
    case Success = 'success';
    case Failure = 'failure';
}
