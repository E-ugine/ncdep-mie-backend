<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Mie\DemoUserSeeder;
use Database\Seeders\Mie\HibiscusScenarioSeeder;
use Database\Seeders\Mie\ReferenceDataSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Deliberately does NOT use WithoutModelEvents: stage 8's notification requirement depends
     * on the real observers (BuyerRequirementObserver, SupplyGapObserver, SupplierMatchObserver,
     * DealObserver) firing exactly as they would via the API — that trait would silently disable
     * all of them and defeat the point.
     *
     * Run order matters for notification correctness: suppliers must exist and the demo user
     * must already be linked to one BEFORE any buyer_requirement/match/deal is created, or the
     * stage-7 observers have no supplier-linked user to notify when those rows are created.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => 'password'],
        );

        $reference = new ReferenceDataSeeder();
        $reference->run();

        $scenario = new HibiscusScenarioSeeder();
        $scenario->seedSupplySide($reference);

        $demoUser = (new DemoUserSeeder())->run($scenario->suppliers['rift']);

        $scenario->seedDemandSideAndChain($reference, $demoUser);

        $this->command?->info('Seeded: '.count($reference->countries).' countries, 1 commodity (Hibiscus, 3 forms/products), '.count($scenario->buyers).' buyers, '.count($scenario->suppliers).' suppliers, '.count($scenario->requirements).' requirements.');
        $this->command?->info("Demo user: {$demoUser->email} / PIN ".DemoUserSeeder::PIN.', linked supplier: '.$scenario->suppliers['rift']->name);
        $this->command?->info("Primary requirement chain walked to contract #{$scenario->activeContract->id} ({$scenario->activeContract->contract_number}), status: {$scenario->activeContract->status->value}.");
    }
}
