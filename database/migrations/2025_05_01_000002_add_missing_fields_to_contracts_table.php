<?php

use App\Enums\ComplianceStatus;
use App\Enums\ShipmentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 3.11's required contract field list cross-checked against the contracts table from
 * stage 1 (deal_id, contract_number, value, volume, currency, incoterm, delivery_date,
 * payment_terms, status).
 *
 * Already present: value, volume, incoterms (incoterm), delivery (delivery_date), payment terms.
 * Computed live, no new columns (per the established pattern — data already has a home via the
 * chain): parties (buyer + supplier, via deal→negotiation→offer→match→buyerRequirement/supplier).
 * Added here because nothing in the schema backs them:
 *   - `price`: distinct from `value` — value is the total contract value, price is the per-unit
 *     price (contracts.value already exists but nothing captured a per-unit figure).
 *   - `documents`: reference/placeholder only, per the task's explicit instruction — a nullable
 *     JSON column for document references, not real file storage.
 *   - `compliance_status`: new small enum (pending/compliant/non_compliant) — nothing in the
 *     schema tracked this at all.
 *   - `shipment_status`: reuses the EXISTING ShipmentStatus enum (already used by the `shipments`
 *     table) rather than inventing a near-duplicate — this is deliberately a single summary field
 *     on the contract itself, not a replacement for the full `shipments` table/workflow, which is
 *     explicitly out of scope this stage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->decimal('price', 12, 2)->after('value');
            $table->json('documents')->nullable()->after('status');
            $table->enum('compliance_status', array_column(ComplianceStatus::cases(), 'value'))
                ->default(ComplianceStatus::Pending->value)
                ->after('documents');
            $table->enum('shipment_status', array_column(ShipmentStatus::cases(), 'value'))
                ->default(ShipmentStatus::Pending->value)
                ->after('compliance_status');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['price', 'documents', 'compliance_status', 'shipment_status']);
        });
    }
};
