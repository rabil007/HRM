<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\CrewOperations\ReconcileCrewOperationalAlerts;
use Illuminate\Console\Command;

class ReconcileCrewOperationalAlertsCommand extends Command
{
    protected $signature = 'crew:reconcile-operational-alerts {--company= : Limit reconciliation to a company id}';

    protected $description = 'Reconcile persisted Crew operational alerts from authoritative domain queries';

    public function handle(ReconcileCrewOperationalAlerts $reconciler): int
    {
        $companyOption = $this->option('company');

        $query = Company::query()
            ->where('status', 'active')
            ->orderBy('id');

        if ($companyOption !== null && $companyOption !== '') {
            $query->whereKey((int) $companyOption);
        }

        $totals = [
            'companies' => 0,
            'created' => 0,
            'updated' => 0,
            'resolved' => 0,
            'errors' => 0,
        ];

        $query->chunkById(50, function ($companies) use ($reconciler, &$totals): void {
            foreach ($companies as $company) {
                $totals['companies']++;
                $result = $reconciler->forCompanySafe((int) $company->id);

                $totals['created'] += $result['created'];
                $totals['updated'] += $result['updated'];
                $totals['resolved'] += $result['resolved'];

                if (isset($result['error'])) {
                    $totals['errors']++;
                    $this->error(sprintf(
                        'Company %d failed: %s',
                        $company->id,
                        $result['error'],
                    ));
                }
            }
        });

        $this->info(sprintf(
            'Reconciled %d companies — created %d, updated %d, resolved %d, errors %d.',
            $totals['companies'],
            $totals['created'],
            $totals['updated'],
            $totals['resolved'],
            $totals['errors'],
        ));

        return $totals['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
