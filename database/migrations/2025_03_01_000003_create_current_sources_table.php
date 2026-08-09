<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 2's core data model chain names "Current Source" as its own node
 * (Requirement → ... → Current Source → Supply Gap → ...), but it wasn't built as a table in
 * the Section 2 pass. Section 3.2 needs it for market scan results, so it's added here, kept
 * deliberately minimal (country + optional supplier name/estimated volume) rather than the full
 * "region, price range, season, route, buyer relationship" field set section 3.6 eventually
 * wants — that's section 3.6's job, not this one. `supplier_name` is free text rather than an FK
 * to our own `suppliers` table because a currently-supplying competitor is very often an outside
 * party never onboarded into this platform's Suppliers at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('current_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_requirement_id')->constrained('buyer_requirements')->cascadeOnDelete();
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->string('supplier_name')->nullable();
            $table->decimal('estimated_volume', 12, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('current_sources');
    }
};
