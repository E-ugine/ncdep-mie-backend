<?php

use App\Enums\OfferStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->decimal('price', 12, 2);
            $table->decimal('volume', 12, 2);
            $table->char('currency', 3);
            $table->enum('status', array_column(OfferStatus::cases(), 'value'))
                ->default(OfferStatus::Pending->value);
            $table->timestamp('valid_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
