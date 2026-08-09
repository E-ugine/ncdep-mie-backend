<?php

namespace App\Enums;

enum SupplierType: string
{
    case Business = 'business';
    case Farm = 'farm';
    case Aggregator = 'aggregator';
    case Processor = 'processor';
    case Exporter = 'exporter';
}
