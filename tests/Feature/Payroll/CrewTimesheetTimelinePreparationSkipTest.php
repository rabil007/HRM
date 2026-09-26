<?php

/**
 * Retired employee-skip HTTP workflow for Crew Timesheet timeline preparation.
 * Skip Support classes may remain dormant; HTTP endpoints are gone.
 */
test('retired crew timeline employee skip routes are unavailable', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    ['user' => $user, 'company' => $company, 'period' => $period, 'employee' => $employee] = $fixtures;

    grantCrewTimelineWorkflowPermissions($user, $company);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post("/payroll/{$period->id}/crew-timeline/1/employees/{$employee->id}/skip", [
            'reason' => 'Not required for this period',
        ])
        ->assertNotFound();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->delete("/payroll/{$period->id}/crew-timeline/1/employees/{$employee->id}/skip")
        ->assertNotFound();
});
