<?php

use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Support\CrewMovements\CrewAssignmentLinkChain;
use Illuminate\Support\Str;

test('a normal assignment chain returns each linked id once', function () {
    ['company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();

    $first = CrewAssignment::factory()->forEmployee($employee)->completed()->create();
    $second = CrewAssignment::factory()->forEmployee($employee)->completed()->create([
        'previous_assignment_id' => $first->id,
    ]);
    $third = CrewAssignment::factory()->forEmployee($employee)->active()->create([
        'previous_assignment_id' => $second->id,
    ]);

    $expected = [$first->id, $second->id, $third->id];
    sort($expected);

    expect(CrewAssignmentLinkChain::assignmentIds($company->id, $employee->id, $third->id))->toBe($expected)
        ->and(CrewAssignmentLinkChain::assignmentIds($company->id, $employee->id, $first->id))->toBe($expected);
});

test('assignment link cycles and self links terminate without duplicate ids', function () {
    ['company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();

    $self = CrewAssignment::factory()->forEmployee($employee)->active()->create();
    $self->update(['previous_assignment_id' => $self->id]);

    $left = CrewAssignment::factory()->forEmployee($employee)->completed()->create();
    $right = CrewAssignment::factory()->forEmployee($employee)->active()->create();
    $left->update(['previous_assignment_id' => $right->id]);
    $right->update(['previous_assignment_id' => $left->id]);

    $cycle = [$left->id, $right->id];
    sort($cycle);

    $first = CrewAssignment::factory()->forEmployee($employee)->completed()->create();
    $second = CrewAssignment::factory()->forEmployee($employee)->completed()->create([
        'previous_assignment_id' => $first->id,
    ]);
    $third = CrewAssignment::factory()->forEmployee($employee)->active()->create([
        'previous_assignment_id' => $second->id,
    ]);
    $first->update(['previous_assignment_id' => $third->id]);

    $triangle = [$first->id, $second->id, $third->id];
    sort($triangle);

    expect(CrewAssignmentLinkChain::assignmentIds($company->id, $employee->id, $self->id))->toBe([$self->id])
        ->and(CrewAssignmentLinkChain::assignmentIds($company->id, $employee->id, $left->id))->toBe($cycle)
        ->and(CrewAssignmentLinkChain::assignmentIds($company->id, $employee->id, $right->id))->toBe($cycle)
        ->and(CrewAssignmentLinkChain::assignmentIds($company->id, $employee->id, $third->id))->toBe($triangle);
});

test('a missing or soft-deleted ancestor does not pull in deleted competing assignments', function () {
    ['company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();
    $otherEmployee = Employee::factory()->forCompany($company)->create();

    $first = CrewAssignment::factory()->forEmployee($employee)->completed()->create();
    $deleted = CrewAssignment::factory()->forEmployee($employee)->completed()->create([
        'previous_assignment_id' => $first->id,
    ]);
    $third = CrewAssignment::factory()->forEmployee($employee)->active()->create([
        'previous_assignment_id' => $deleted->id,
    ]);
    $deleted->delete();

    $other = CrewAssignment::factory()->forEmployee($otherEmployee)->active()->create([
        'previous_assignment_id' => $first->id,
    ]);

    $otherCompany = Company::query()->create([
        'name' => 'Other Crew Co',
        'slug' => 'other-crew-'.Str::lower(Str::random(6)),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $company->country_id,
        'currency_id' => $company->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    $foreignEmployee = Employee::factory()->forCompany($otherCompany)->create();
    $foreign = CrewAssignment::factory()->forEmployee($foreignEmployee)->active()->create([
        'previous_assignment_id' => $first->id,
    ]);

    expect(CrewAssignmentLinkChain::assignmentIds($company->id, $employee->id, $first->id))->toBe([$first->id])
        ->and(CrewAssignmentLinkChain::assignmentIds($company->id, $employee->id, $third->id))->toBe([$third->id])
        ->and(CrewAssignmentLinkChain::assignmentIds($company->id, $employee->id, $other->id))->toBe([$other->id])
        ->and(CrewAssignmentLinkChain::assignmentIds($company->id, $otherEmployee->id, $first->id))->toBe([$first->id])
        ->and(CrewAssignmentLinkChain::assignmentIds($otherCompany->id, $foreignEmployee->id, $foreign->id))->toBe([$foreign->id])
        ->and(CrewAssignmentLinkChain::assignmentIds($company->id, $employee->id, $foreign->id))->toBe([$foreign->id]);
});
