<?php

namespace App\Enums;

enum ModuleAccessAttemptType: string
{
    case PhoneOtp = 'phone_otp';
    case Pin = 'pin';
}
