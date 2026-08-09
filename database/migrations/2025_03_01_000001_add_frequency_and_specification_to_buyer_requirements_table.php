<?php

use App\Enums\RequirementFrequency;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 3.4's field list requires `frequency` on the requirement record, and section 3.2's
 * result set requires an open-ended `specification` (size/grade/packaging/moisture/certification/
 * residue limits/cold-chain). Neither existed on buyer_requirements from the Section 2 build.
 *
 * `specification` is a single JSON column rather than one column per attribute: the attribute
 * list is open-ended and varies per commodity (an avocado spec and a cut-flower spec share almost
 * nothing), so a fixed column set would either be mostly-null for any given commodity or need a
 * migration every time a new commodity introduces a new spec attribute. JSON fits an
 * open/variable schema; individual columns would fit a fixed one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyer_requirements', function (Blueprint $table) {
            $table->enum('frequency', array_column(RequirementFrequency::cases(), 'value'))
                ->nullable()
                ->after('volume');
            $table->json('specification')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('buyer_requirements', function (Blueprint $table) {
            $table->dropColumn(['frequency', 'specification']);
        });
    }
};
