<?php

use App\Enums\ContractStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals')->restrictOnDelete();
            $table->string('contract_number')->unique();
            $table->decimal('value', 14, 2);
            $table->decimal('volume', 12, 2);
            $table->char('currency', 3);
            $table->string('incoterm');
            $table->date('delivery_date');
            $table->string('payment_terms');
            $table->enum('status', array_column(ContractStatus::cases(), 'value'))
                ->default(ContractStatus::Draft->value);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
