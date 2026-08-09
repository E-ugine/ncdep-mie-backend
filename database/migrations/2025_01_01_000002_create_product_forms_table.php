<?php

use App\Enums\ProductFormState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commodity_id')->constrained('commodities')->cascadeOnDelete();
            $table->enum('state', array_column(ProductFormState::cases(), 'value'));
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['commodity_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_forms');
    }
};
