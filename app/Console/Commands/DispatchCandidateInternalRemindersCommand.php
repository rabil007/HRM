<?php

namespace App\Console\Commands;

use App\Support\Recruitment\Candidates\DispatchCandidateInternalReminders;
use Illuminate\Console\Command;

class DispatchCandidateInternalRemindersCommand extends Command
{
    protected $signature = 'recruitment:dispatch-candidate-reminders
                            {--company= : Limit dispatching to a specific company ID}
                            {--force : Force dispatch ignoring the company-local 09:00 window}';

    protected $description = 'Queue Recruitment Candidate interview and joining internal reminders for companies at local 09:00';

    public function handle(DispatchCandidateInternalReminders $dispatcher): int
    {
        $companyOption = $this->option('company');
        $force = (bool) $this->option('force');

        $onlyCompanyId = ($companyOption !== null && $companyOption !== '')
            ? (int) $companyOption
            : null;

        $results = $dispatcher->dispatchAll($force, $onlyCompanyId);

        $this->info(sprintf(
            'Checked %d company(s) — queued %d reminder job(s), skipped %d, errors %d.',
            $results['companies_checked'],
            $results['reminders_queued'],
            $results['skipped'],
            $results['errors'],
        ));

        return $results['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
