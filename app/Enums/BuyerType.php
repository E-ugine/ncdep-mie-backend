<?php

namespace App\Enums;

enum BuyerType: string
{
    case Importer = 'importer';
    case Distributor = 'distributor';
    case Retailer = 'retailer';
    case Processor = 'processor';
    case Manufacturer = 'manufacturer';
    case Wholesaler = 'wholesaler';
    case Trader = 'trader';
    case Foodservice = 'foodservice';
    case Other = 'other';
}
