<?php

/**
 * Retired Crew Timesheet preparation review page (filters / search / departments).
 * Filter Support may remain dormant; the HTTP review route is gone.
 */
test('retired crew timeline review filter route is unavailable', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    ['user' => $user, 'company' => $company, 'period' => $period] = $fixtures;

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.prepare',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get("/payroll/{$period->id}/crew-timeline/1")
        ->assertNotFound();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get("/payroll/{$period->id}/crew-timeline/1?search=ops&department_id=1")
        ->assertNotFound();
});
