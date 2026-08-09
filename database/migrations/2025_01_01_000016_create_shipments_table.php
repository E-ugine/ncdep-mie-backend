<?php

use App\Enums\ShipmentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->restrictOnDelete();
            $table->string('tracking_number')->nullable();
            $table->enum('status', array_column(ShipmentStatus::cases(), 'value'))
                ->default(ShipmentStatus::Pending->value);
            $table->decimal('volume', 12, 2);
            $table->date('departure_date')->nullable();
            $table->date('arrival_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
