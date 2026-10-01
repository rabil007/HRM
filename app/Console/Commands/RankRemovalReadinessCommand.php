<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\MasterData\RankRemovalReadiness;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('master-data:rank-removal-readiness {--company= : Limit to a company ID}')]
#[Description('Read-only readiness check before destructive Rank schema removal')]
class RankRemovalReadinessCommand extends Command
{
    public function handle(RankRemovalReadiness $readiness): int
    {
        $companyOption = $this->option('company');
        $companyId = null;

        if (is_string($companyOption) && $companyOption !== '') {
            if (! ctype_digit($companyOption)) {
                $this->error('The --company option must be a numeric company ID.');

                return self::FAILURE;
            }

            $companyId = (int) $companyOption;

            if (Company::withTrashed()->whereKey($companyId)->doesntExist()) {
                $this->error("Company [{$companyId}] was not found.");

                return self::FAILURE;
            }
        }

        $this->info('Running read-only Rank removal readiness checks...');

        try {
            $report = $readiness->report($companyId);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (($report['already_removed'] ?? false) === true) {
            $this->info('Rank schema already removed.');
            $this->info('Ready for Rank removal: YES');

            return self::SUCCESS;
        }

        foreach ($report['companies'] as $companyReport) {
            $status = $companyReport['ready'] ? 'READY' : 'NOT READY';
            $this->line(sprintf(
                '[%s] Company #%d %s',
                $status,
                $companyReport['company_id'],
                $companyReport['company_name'] !== '' ? '('.$companyReport['company_name'].')' : '',
            ));

            foreach ($companyReport['counts'] as $key => $count) {
                if ((int) $count > 0) {
                    $label = RankRemovalReadiness::TOTAL_LABELS[$key] ?? $key;
                    $this->warn(sprintf('  - %s: %d', $label, $count));
                }
            }
        }

        $this->newLine();
        $this->line('Totals:');

        $highlightKeys = [
            'rank_position_tod_conflicts',
            'rank_position_status_conflicts',
            'vessel_manning_position_collisions',
            'saved_views_remaining_rank_filter',
        ];

        foreach ($highlightKeys as $key) {
            $count = (int) ($report['totals'][$key] ?? 0);
            $label = RankRemovalReadiness::TOTAL_LABELS[$key] ?? $key;
            $this->line(sprintf('  %s: %d', $label, $count));
        }

        foreach ($report['totals'] as $key => $count) {
            if (in_array($key, $highlightKeys, true)) {
                continue;
            }

            $label = RankRemovalReadiness::TOTAL_LABELS[$key] ?? $key;
            $this->line(sprintf('  %s: %d', $label, $count));
        }

        $this->newLine();

        if ($report['ready']) {
            $this->info('Ready for Rank removal: YES');

            return self::SUCCESS;
        }

        $this->error('Ready for Rank removal: NO');
        $this->error('Resolve unresolved Rank→Position coverage before running the destructive migration.');
        $this->error('Do not delete operational history solely to clear readiness — resolve Positioning conflicts explicitly.');

        return self::FAILURE;
    }
}
