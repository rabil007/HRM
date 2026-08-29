<?php

namespace App\Console\Commands;

use App\Support\Documents\RecipientRequests\Delivery\DispatchDocumentRecipientRequestEmails;
use Illuminate\Console\Command;

class DispatchDocumentRecipientRequestEmailsCommand extends Command
{
    protected $signature = 'documents:dispatch-recipient-emails {--company=}';

    protected $description = 'Dispatch queued document recipient request emails that have not completed queue handoff';

    public function handle(DispatchDocumentRecipientRequestEmails $dispatcher): int
    {
        $companyId = $this->option('company');
        $onlyCompanyId = is_numeric($companyId) ? (int) $companyId : null;

        $result = $dispatcher->dispatchPending($onlyCompanyId);

        $this->info(sprintf(
            'Document recipient emails: dispatched %d, skipped %d.',
            $result['dispatched'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
