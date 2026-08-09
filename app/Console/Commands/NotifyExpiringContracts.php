<?php

namespace App\Console\Commands;

use App\Enums\ShipmentStatus;
use App\Models\Contract;
use App\Models\Notification;
use Illuminate\Console\Command;

/**
 * Section 3.15's "contract expiring" trigger — this one needs a scheduled check, not an
 * observer, since nothing about time passing fires an Eloquent event. Reuses the SAME config
 * value as section 3.11's ContractController "expiring" view (config('mie.contracts.expiring_
 * within_days')) rather than introducing a second, possibly-inconsistent "expiring window"
 * definition.
 *
 * NOTE: "delivery_window_end" per the task's wording doesn't exist on contracts (that's a
 * buyer_requirements field, added in stage 3) — contracts have a single `delivery_date`, the
 * same terminology gap already resolved identically in stage 5's ContractController.
 *
 * Real-time delivery is explicitly out of scope for this build (no websockets/broadcasting) —
 * this command only inserts notification rows. Wiring it into Laravel's scheduler
 * (`Schedule::command('mie:notify-expiring-contracts')->daily()` in routes/console.php) is a
 * one-line addition, deliberately not done here since this dev setup has no persistent cron
 * process to run it.
 */
class NotifyExpiringContracts extends Command
{
    protected $signature = 'mie:notify-expiring-contracts';

    protected $description = 'Notify supplier-linked users about contracts nearing delivery that have not yet shipped as delivered.';

    public function handle(): int
    {
        $days = (int) config('mie.contracts.expiring_within_days');

        $contracts = Contract::with('deal.negotiation.offer.match.supplier.users')
            ->where('shipment_status', '!=', ShipmentStatus::Delivered->value)
            ->whereDate('delivery_date', '>=', now()->toDateString())
            ->whereDate('delivery_date', '<=', now()->addDays($days)->toDateString())
            ->get();

        $notified = 0;

        foreach ($contracts as $contract) {
            $supplier = $contract->deal?->negotiation?->offer?->match?->supplier;

            if (! $supplier) {
                continue;
            }

            foreach ($supplier->users as $user) {
                Notification::create([
                    'user_id' => $user->id,
                    'notifiable_type' => Contract::class,
                    'notifiable_id' => $contract->id,
                    'type' => 'contract_expiring',
                    'data' => [
                        'contract_number' => $contract->contract_number,
                        'delivery_date' => $contract->delivery_date->toDateString(),
                    ],
                ]);
                $notified++;
            }
        }

        $this->info("Notified {$notified} user(s) across {$contracts->count()} expiring contract(s) (within {$days} days, not yet delivered).");

        return self::SUCCESS;
    }
}
