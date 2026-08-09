<?php

use App\Enums\Incoterm;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 3.3's "current open needs" view and section 3.4's field list both require an incoterm
 * per requirement; buyer_requirements never had one. Modeled as a proper enum (the 11 Incoterms
 * 2020 rules are a fixed, standardized list — unlike e.g. `industry` this isn't open-ended), even
 * though `contracts.incoterm` (built in section 2) uses a plain string — not changing that
 * existing column, just not repeating its looseness here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyer_requirements', function (Blueprint $table) {
            $table->enum('incoterm', array_column(Incoterm::cases(), 'value'))->nullable()->after('delivery_window_end');
        });
    }

    public function down(): void
    {
        Schema::table('buyer_requirements', function (Blueprint $table) {
            $table->dropColumn('incoterm');
        });
    }
};
