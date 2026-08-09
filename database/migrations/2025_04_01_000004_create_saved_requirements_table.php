<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * No "saved item" concept exists anywhere in the schema yet. Section 3.4's /save action needs
 * it now; section 3.13's "My Market: saved opportunities" will need the same data later, so the
 * table is built here rather than deferred twice. Kept minimal per the task's own spec: user_id,
 * buyer_requirement_id, created_at — no updated_at (a save either exists or doesn't; there's
 * nothing to update). Unique on (user_id, buyer_requirement_id) so saving twice is a no-op, not
 * a duplicate row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('buyer_requirement_id')->constrained('buyer_requirements')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'buyer_requirement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_requirements');
    }
};
