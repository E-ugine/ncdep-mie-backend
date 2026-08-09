<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the user↔supplier gap flagged (and deliberately left open) in the section 3.1 command
 * center work: this module has one implicit user type throughout the spec (the supplier/exporter),
 * but nothing linked a logged-in `users` row to a `suppliers` profile. One user maps to at most
 * one supplier profile — a plain nullable FK, not a pivot table — since the spec gives no
 * indication of multi-supplier users or dual buyer/supplier roles. Nullable because most users
 * (or all, until this is populated) won't have one yet. restrictOnDelete so a supplier record
 * can't be deleted out from under a user that references it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('pin_hash')
                ->constrained('suppliers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
        });
    }
};
