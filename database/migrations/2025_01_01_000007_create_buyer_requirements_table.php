<?php

use App\Enums\BuyerRequirementStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('buyers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('market_id')->constrained('markets')->restrictOnDelete();
            $table->decimal('volume', 12, 2);
            $table->enum('status', array_column(BuyerRequirementStatus::cases(), 'value'))
                ->default(BuyerRequirementStatus::Open->value);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_requirements');
    }
};
