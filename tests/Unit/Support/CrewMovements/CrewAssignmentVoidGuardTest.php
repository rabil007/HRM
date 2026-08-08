<?php

use App\Models\CrewAssignment;
use App\Support\CrewMovements\CrewAssignmentVoidGuard;
use App\Support\CrewMovements\CrewMovementService;

it('returns no blockers for a plain draft assignment', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ]);

    expect(app(CrewAssignmentVoidGuard::class)->blockers($assignment, $company->id))->toBe([]);
});

it('returns already_voided for soft-deleted assignment', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ]);
    $assignment->forceFill([
        'voided_at' => now(),
        'voided_by' => $user->id,
        'void_reason' => 'test',
    ])->save();
    $assignment->delete();

    $codes = collect(app(CrewAssignmentVoidGuard::class)->blockers(
        CrewAssignment::withTrashed()->findOrFail($assignment->id),
        $company->id,
    ))->pluck('code');

    expect($codes)->toContain('already_voided');
});
