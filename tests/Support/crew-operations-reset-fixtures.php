<?php

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Testing\PendingCommand;

const CREW_RESET_CONFIRMATION = 'RESET CREW OPERATIONS DATA';

function runCrewResetWithConfirmation(TestCase $test, ?int $companyId = null): PendingCommand
{
    $parameters = $companyId !== null ? ['--company' => $companyId] : [];

    return $test->artisan('crew:reset', $parameters)
        ->expectsQuestion('Type `'.CREW_RESET_CONFIRMATION.'` to permanently delete the rows listed above', CREW_RESET_CONFIRMATION);
}
