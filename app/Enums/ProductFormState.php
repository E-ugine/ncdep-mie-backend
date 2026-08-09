<?php

namespace App\Enums;

enum ProductFormState: string
{
    case Fresh = 'fresh';
    case Raw = 'raw';
    case Processed = 'processed';
}
