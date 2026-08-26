<?php

use Inertia\Testing\AssertableInertia as Assert;

/**
 * Assert employee profile tab record props via partial reload (Inertia::optional).
 *
 * @param  list<string>|null  $only
 */
function assertEmployeeProfileRecords(Assert $page, ?Closure $callback = null, ?array $only = null): Assert
{
    $props = $only ?? [
        'contracts',
        'documents',
        'required_documents',
        'education_qualifications',
        'work_experiences',
        'vaccinations',
        'languages',
        'trainings',
        'courses',
        'bank_accounts',
        'sea_services',
        'document_types',
        'vessel_types',
        'vessels',
        'clients',
    ];

    return $page->reloadOnly(
        $props,
        $callback ?? static function (Assert $assertable): void {
            $assertable->has('contracts')
                ->has('documents')
                ->has('required_documents')
                ->has('education_qualifications')
                ->has('work_experiences')
                ->has('vaccinations')
                ->has('languages')
                ->has('trainings')
                ->has('courses')
                ->has('bank_accounts')
                ->has('sea_services')
                ->has('document_types')
                ->has('vessel_types')
                ->has('clients');
        },
    );
}
