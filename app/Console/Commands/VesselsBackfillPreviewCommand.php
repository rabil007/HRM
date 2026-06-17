<?php

namespace App\Console\Commands;

use App\Support\Vessels\BackfillVesselsFromLegacyNames;
use Illuminate\Console\Command;

class VesselsBackfillPreviewCommand extends Command
{
    protected $signature = 'vessels:backfill-preview';

    protected $description = 'Preview vessel master data backfill from legacy sea service vessel names';

    public function handle(BackfillVesselsFromLegacyNames $backfill): int
    {
        $result = $backfill->preview();

        if ($result['distinct_names'] === 0) {
            $this->warn('No legacy vessel_name column found, or no sea service rows to backfill.');

            return self::SUCCESS;
        }

        $this->info("Found {$result['distinct_names']} distinct vessel name(s)");
        $this->info("Will create {$result['vessels_to_create']} vessel master record(s)");
        $this->info("{$result['sea_services_linked']} sea service row(s) would be linked");
        $this->info("{$result['sea_services_unlinked']} sea service row(s) would remain unlinked");
        $this->info("{$result['deployments_linked']} deployment row(s) would be linked");

        foreach ($result['type_conflicts'] as $conflict) {
            $this->warn("Type conflict: {$conflict}");
        }

        foreach ($result['grt_conflicts'] as $conflict) {
            $this->warn("GRT conflict: {$conflict}");
        }

        foreach ($result['bhp_conflicts'] as $conflict) {
            $this->warn("BHP conflict: {$conflict}");
        }

        return self::SUCCESS;
    }
}
