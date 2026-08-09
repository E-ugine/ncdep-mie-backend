<?php

use App\Enums\BuyerType;
use App\Enums\BuyerVerificationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 3.3's buyer profile field list cross-checked against the buyers table built in stage 1
 * (id, country_id, name, description, timestamps). Present already: company (name), country.
 * Missing, added here: buyer_type, industry, hq, payment_terms, currency, preferred_ports,
 * logistics_preferences, verification_status.
 *
 * Deliberately NOT added here (computed live from existing relations instead, see BuyerController):
 * operating markets, products purchased/sold, annual procurement, current suppliers + countries,
 * procurement frequency, typical order size, preferred specifications, packaging requirements,
 * delivery requirements, historical buying activity, open requirements, active RFQs, existing
 * contracts, market relationships, trade readiness, reliability indicators — all of these already
 * have a home in buyer_requirements/current_sources/matches/offers/negotiations/deals/contracts,
 * so adding columns for them would just be redundant, driftable copies of real relational data.
 * `sustainability_indicators` is intentionally not added at all — no certification/sustainability
 * data exists anywhere in the schema to back it, so it's returned as null with an explicit note
 * rather than a column that looks like real capability.
 *
 * `industry` is a plain string, not an enum: industries are open-ended (unlike buyer_type, which
 * is a small fixed set), so a fixed list would either be too restrictive or need constant upkeep.
 * `preferred_ports` and `logistics_preferences` are JSON for the same reason `specification` is
 * JSON on buyer_requirements — open-ended, variable per buyer, not a fixed column set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->enum('buyer_type', array_column(BuyerType::cases(), 'value'))->nullable()->after('name');
            $table->string('industry')->nullable()->after('buyer_type');
            $table->string('hq')->nullable()->after('industry');
            $table->string('payment_terms')->nullable()->after('description');
            $table->char('currency', 3)->nullable()->after('payment_terms');
            $table->json('preferred_ports')->nullable()->after('currency');
            $table->json('logistics_preferences')->nullable()->after('preferred_ports');
            $table->enum('verification_status', array_column(BuyerVerificationStatus::cases(), 'value'))
                ->default(BuyerVerificationStatus::Unverified->value)
                ->after('logistics_preferences');
        });
    }

    public function down(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->dropColumn([
                'buyer_type', 'industry', 'hq', 'payment_terms', 'currency',
                'preferred_ports', 'logistics_preferences', 'verification_status',
            ]);
        });
    }
};
