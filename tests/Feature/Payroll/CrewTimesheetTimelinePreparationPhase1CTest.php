<?php

/**
 * Retired Crew Timesheet approval HTTP workflow (show / submit / approve / return).
 * Behavioural coverage for prepare→apply now lives in EmbeddedWorkspaceTest and Phase1B/D.
 */
test('retired crew timeline review and approval routes are unavailable', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    ['user' => $user, 'company' => $company, 'period' => $period] = $fixtures;

    grantCrewTimelineWorkflowPermissions($user, $company);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get("/payroll/{$period->id}/crew-timeline/1")
        ->assertNotFound();

    foreach ([
        "/payroll/{$period->id}/crew-timeline/1/submit",
        "/payroll/{$period->id}/crew-timeline/1/approve",
        "/payroll/{$period->id}/crew-timeline/1/return",
    ] as $path) {
        $this->actingAs($user)
            ->withSession(['current_company_id' => $company->id])
            ->post($path)
            ->assertNotFound();
    }
});
