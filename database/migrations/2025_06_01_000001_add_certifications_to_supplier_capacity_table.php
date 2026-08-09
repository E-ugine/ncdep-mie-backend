<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 3.16's `user_capability` match component explicitly requires comparing "supplier_capacity
 * certifications vs. requirement specification json" — but supplier_capacity never had a
 * certifications field (Section 2 only ever gave suppliers country/name/type). Without this
 * column, user_capability could never contribute real data to any match score, ever — defeating
 * the point of naming it as a component. Nullable JSON (an array of certification strings), same
 * reasoning as `specification` on buyer_requirements: an open-ended, variable-length list, not a
 * fixed column set. Nullable, not defaulted to `[]`, so "no data recorded" (null) stays
 * distinguishable from "recorded and empty" (real data: this supplier holds no certifications).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_capacity', function (Blueprint $table) {
            $table->json('certifications')->nullable()->after('available_volume');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_capacity', function (Blueprint $table) {
            $table->dropColumn('certifications');
        });
    }
};
