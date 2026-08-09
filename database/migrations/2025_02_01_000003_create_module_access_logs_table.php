<?php

use App\Enums\ModuleAccessAttemptType;
use App\Enums\ModuleAccessOutcome;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('attempt_type', array_column(ModuleAccessAttemptType::cases(), 'value'));
            $table->enum('outcome', array_column(ModuleAccessOutcome::cases(), 'value'));
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_access_logs');
    }
};
