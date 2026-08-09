<?php

namespace App\Models;

use App\Enums\ShipmentStatus;
use Database\Factories\ShipmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['contract_id', 'tracking_number', 'status', 'volume', 'departure_date', 'arrival_date'])]
class Shipment extends Model
{
    /** @use HasFactory<ShipmentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'volume' => 'decimal:2',
            'departure_date' => 'date',
            'arrival_date' => 'date',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
