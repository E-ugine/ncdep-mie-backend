<?php

use App\Enums\NegotiationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('negotiations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->enum('status', array_column(NegotiationStatus::cases(), 'value'))
                ->default(NegotiationStatus::Open->value);
            $table->decimal('counter_price', 12, 2)->nullable();
            $table->decimal('counter_volume', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('negotiations');
    }
};
