<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 3.2's result set requires a "delivery window" per requirement. This wasn't part of
 * the three additions anticipated going into this task (frequency, current source,
 * specification) — it's a genuine 4th gap, not computable from any existing field, so it gets
 * its own migration called out on its own rather than folded into the frequency/specification one.
 * Modeled as a start/end date pair (a "window" is a range, not a single date).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyer_requirements', function (Blueprint $table) {
            $table->date('delivery_window_start')->nullable()->after('specification');
            $table->date('delivery_window_end')->nullable()->after('delivery_window_start');
        });
    }

    public function down(): void
    {
        Schema::table('buyer_requirements', function (Blueprint $table) {
            $table->dropColumn(['delivery_window_start', 'delivery_window_end']);
        });
    }
};
