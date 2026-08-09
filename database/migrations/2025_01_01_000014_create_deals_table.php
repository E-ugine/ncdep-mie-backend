<?php

use App\Enums\DealPipelineStage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('negotiation_id')->constrained('negotiations')->cascadeOnDelete();
            $table->enum('pipeline_stage', array_column(DealPipelineStage::cases(), 'value'))
                ->default(DealPipelineStage::Open->value);
            $table->decimal('agreed_price', 12, 2);
            $table->decimal('agreed_volume', 12, 2);
            $table->char('currency', 3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
