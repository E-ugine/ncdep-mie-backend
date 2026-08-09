<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supply_gaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_requirement_id')->constrained('buyer_requirements')->cascadeOnDelete();
            $table->decimal('demand_volume', 12, 2);
            $table->decimal('contracted_volume', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_gaps');
    }
};
