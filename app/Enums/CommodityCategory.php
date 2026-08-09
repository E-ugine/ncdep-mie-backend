<?php

namespace App\Enums;

enum CommodityCategory: string
{
    case Crop = 'crop';
    case Livestock = 'livestock';
    case Aquaculture = 'aquaculture';
    case Input = 'input';
    case ByProduct = 'by_product';
}
