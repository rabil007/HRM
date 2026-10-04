<?php

use App\Enums\CrewAssignmentStatus;
use App\Models\CrewAssignment;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\CrewOperations\CrewReadinessFilters;
use App\Support\CrewOperations\CrewReadinessQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

it('keeps crew readiness query count bounded as pre-join rows grow', function () {
    $fixtures = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.planning.view',
        'crew_operations.assignments.view',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    $timezone = $fixtures['company']->timezone ?? 'Asia/Dubai';
    $today = CarbonImmutable::parse('2026-10-04 08:00:00', $timezone);
    Carbon::setTestNow($today);
    CarbonImmutable::setTestNow($today);

    $vessel = makeCrewMovementVessel('Readiness Count Vessel', $fixtures['company']);

    $type = DocumentType::query()->create(['title' => 'Passport '.uniqid(), 'is_active' => true]);
    makeDocumentRequirement($fixtures['company']->id, $type->id, requiredForAll: true);

    $makePreJoin = function (int $index) use ($fixtures, $vessel, $type, $today): void {
        $employee = Employee::factory()->forCompany($fixtures['company'])->create([
            'position_id' => $fixtures['rank']->id,
            'status' => 'active',
        ]);

        EmployeeDocument::query()->create([
            'company_id' => $fixtures['company']->id,
            'employee_id' => $employee->id,
            'document_type_id' => $type->id,
            'type' => 'other',
            'document_type' => (string) $type->id,
            'file_path' => 'employee-documents/test.pdf',
            'status' => 'valid',
            'expiry_date' => $today->addMonths(12)->toDateString(),
        ]);

        CrewAssignment::factory()->forEmployee($employee)->create([
            'company_id' => $fixtures['company']->id,
            'vessel_id' => $vessel->id,
            'position_id' => $fixtures['rank']->id,
            'status' => CrewAssignmentStatus::Draft,
            'planned_join_at' => $today->addDays($index % 10 + 1)->toDateTimeString(),
        ]);
    };

    $makePreJoin(0);

    $query = app(CrewReadinessQuery::class);
    $filters = CrewReadinessFilters::normalize([]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $one = $query->page((int) $fixtures['company']->id, $filters, $fixtures['user'], 1, '/organization/crew-operations/crew-readiness');
    $oneCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($one['pagination']->total())->toBe(1);

    for ($i = 1; $i < 12; $i++) {
        $makePreJoin($i);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $many = $query->page((int) $fixtures['company']->id, $filters, $fixtures['user'], 1, '/organization/crew-operations/crew-readiness');
    $manyCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($many['pagination']->total())->toBe(12)
        ->and($manyCount)->toBeLessThanOrEqual($oneCount + 8)
        ->and($manyCount - $oneCount)->toBeLessThan(12);

    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});
