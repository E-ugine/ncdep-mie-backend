<?php

use App\Enums\SupplierType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->string('name');
            $table->enum('type', array_column(SupplierType::cases(), 'value'));
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
