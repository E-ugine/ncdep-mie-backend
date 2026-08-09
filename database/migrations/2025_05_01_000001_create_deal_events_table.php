<?php

use App\Enums\DealEventType;
use App\Enums\DealPipelineStage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 3.10's "full commercial timeline/audit trail" — a real append-only log, not a derived
 * view. Every row is written automatically by DealObserver (on deal creation and on every
 * pipeline_stage change), never by a controller calling this table directly.
 *
 * `from_stage`/`to_stage` are enum columns (not plain strings) matching DealPipelineStage's exact
 * values, for the same DB-level integrity reasons every other stage/status column in this schema
 * is an enum. `from_stage` is nullable — the very first event (deal creation) has no "from".
 * `actor_user_id` is nullable with nullOnDelete (not cascade): deleting a user shouldn't erase a
 * deal's audit history, just lose the specific actor reference on that row — this is deliberately
 * different from module_access_logs' cascade choice, because that log is inherently per-user,
 * while a deal's timeline belongs to the deal and can span many actors over its lifetime.
 * No updated_at — this is a log, append-only, same precedent as module_access_logs/phone_otps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->enum('event_type', array_column(DealEventType::cases(), 'value'));
            $table->enum('from_stage', array_column(DealPipelineStage::cases(), 'value'))->nullable();
            $table->enum('to_stage', array_column(DealPipelineStage::cases(), 'value'));
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_events');
    }
};
