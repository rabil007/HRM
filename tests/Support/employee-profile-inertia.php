<?php

use Inertia\Testing\AssertableInertia as Assert;

/**
 * Assert deferred employee profile record props (loaded after initial paint).
 */
function assertEmployeeProfileRecords(Assert $page, ?\Closure $callback = null): Assert
{
    return $page->loadDeferredProps(
        'employee_profile_records',
        $callback ?? static function (Assert $assertable): void {
            $assertable->has('contracts')
                ->has('documents')
                ->has('education_qualifications')
                ->has('work_experiences')
                ->has('vaccinations')
                ->has('languages')
                ->has('bank_accounts')
                ->has('sea_services')
                ->has('document_types')
                ->has('vessel_types')
                ->has('clients');
        },
    );
}
