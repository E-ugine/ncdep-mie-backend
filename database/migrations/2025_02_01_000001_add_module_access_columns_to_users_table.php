<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `phone` and `phone_verified_at` are stand-ins: per spec section 1.1 this module only
     * CONSUMES verified-phone status from the account/identity module, it does not own phone
     * verification. Those columns don't exist yet anywhere in this project, so they're added
     * here as minimal placeholders. If/when the identity module defines its own canonical
     * phone fields, this migration's two columns should be reconciled with (or replaced by) them.
     *
     * `pin_hash` is genuinely owned by this module — the PIN is separate from the account
     * password and only gates the Market Intelligence and Exchange module.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->unique()->after('email');
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
            $table->string('pin_hash')->nullable()->after('phone_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'phone_verified_at', 'pin_hash']);
        });
    }
};
