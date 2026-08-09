<?php

namespace App\Enums;

enum DealPipelineStage: string
{
    case Open = 'open';
    case Negotiating = 'negotiating';
    case AwaitingBuyer = 'awaiting_buyer';
    case AwaitingSupplier = 'awaiting_supplier';
    case ContractPending = 'contract_pending';
    case ContractSigned = 'contract_signed';
    case InProduction = 'in_production';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case PaymentPending = 'payment_pending';
    case Completed = 'completed';
}
