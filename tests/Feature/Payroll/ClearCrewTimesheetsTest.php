<?php

use App\Enums\CrewTimesheetApprovalStatus;
use App\Enums\CrewTimesheetMode;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\CrewTimesheet;
use App\Models\PayrollPeriod;
use App\Support\Payroll\Actions\ClearManualImportCrewTimesheets;
use App\Support\Payroll\Actions\UpsertCrewTimesheet;
use App\Support\Payroll\BuildCrewPayrollGenerationPreview;
use App\Support\Payroll\ClearableManualImportCrewTimesheetsQuery;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

function grantClearTimesheetPermissions($user, $company): void
{
    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.clear',
        'payroll.crew_timesheets.create',
        'payroll.crew_timesheets.update',
        'payroll.crew_timesheets.import',
    ]);
}

test('authorised user can clear all manual timesheets in a draft crew period', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantClearTimesheetPermissions($user, $company);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $a = createCrewEmployeeWithContract($company, 'CLR-M1', 100, 50, 25);
    $b = createCrewEmployeeWithContract($company, 'CLR-M2', 100, 50, 25);

    $first = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $a->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'onsite_days' => 8,
    ]);
    $second = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $b->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'onsite_days' => 5,
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->delete(route('payroll.crew-timesheets.clear-manual-import', $period))
        ->assertRedirect()
        ->assertSessionHas('success', '2 Manual/Imported timesheets cleared.');

    expect(CrewTimesheet::query()->where('period_id', $period->id)->count())->toBe(0)
        ->and(CrewTimesheet::withTrashed()->find($first->id)?->trashed())->toBeTrue()
        ->and(CrewTimesheet::withTrashed()->find($second->id)?->trashed())->toBeTrue();
});

test('authorised user can clear import timesheets and mixed sources together', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantClearTimesheetPermissions($user, $company);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $manual = createCrewEmployeeWithContract($company, 'CLR-MIX-M', 100, 50, 25);
    $import = createCrewEmployeeWithContract($company, 'CLR-MIX-I', 100, 50, 25);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $manual->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'onsite_days' => 4,
    ]);
    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $import->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Import,
        'onsite_days' => 6,
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->delete(route('payroll.crew-timesheets.clear-manual-import', $period))
        ->assertRedirect()
        ->assertSessionHas('success', '2 Manual/Imported timesheets cleared.');

    expect(CrewTimesheet::query()->where('period_id', $period->id)->count())->toBe(0);
});

test('crew operations preparation-linked and operationally locked timesheets remain untouched', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['period']->update(['crew_timesheet_mode' => CrewTimesheetMode::Hybrid]);
    grantApplyPermissions($fixtures['user'], $fixtures['company'], [
        'payroll.periods.view',
        'payroll.crew_timesheets.clear',
        'payroll.crew_timesheets.import',
    ]);

    ['preparation' => $preparation, 'approver' => $approver] = prepareApprovedTimeline($fixtures);

    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]))
        ->assertRedirect();

    grantClearTimesheetPermissions($fixtures['user'], $fixtures['company']);

    $opsTimesheet = CrewTimesheet::query()
        ->where('period_id', $fixtures['period']->id)
        ->where('employee_id', $fixtures['employee']->id)
        ->firstOrFail();

    $manualEmployee = createCrewEmployeeWithContract($fixtures['company'], 'CLR-OPS-M', 100, 50, 25);
    CrewTimesheet::factory()->create([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $manualEmployee->id,
        'period_id' => $fixtures['period']->id,
        'source' => CrewTimesheetSource::Manual,
        'onsite_days' => 3,
    ]);

    expect($opsTimesheet->source)->toBe(CrewTimesheetSource::CrewOperations)
        ->and($opsTimesheet->crew_timesheet_preparation_id)->toBe($preparation->id)
        ->and($opsTimesheet->isOperationallyLocked())->toBeTrue();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->delete(route('payroll.crew-timesheets.clear-manual-import', $fixtures['period']))
        ->assertRedirect()
        ->assertSessionHas('success', '1 Manual/Imported timesheets cleared.');

    $opsTimesheet->refresh();

    expect($opsTimesheet->trashed())->toBeFalse()
        ->and($opsTimesheet->source)->toBe(CrewTimesheetSource::CrewOperations)
        ->and($opsTimesheet->crew_timesheet_preparation_id)->toBe($preparation->id)
        ->and($preparation->fresh()->status->value)->toBe('applied')
        ->and(CrewTimesheet::query()->where('period_id', $fixtures['period']->id)->count())->toBe(1);
});

test('legacy null-source rows are cleared as manual', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantClearTimesheetPermissions($user, $company);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, 'CLR-NULL', 100, 50, 25);

    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'onsite_days' => 2,
    ]);
    $timesheet->forceFill(['source' => null])->saveQuietly();

    expect($timesheet->fresh()->resolvedSource())->toBe(CrewTimesheetSource::Manual);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->delete(route('payroll.crew-timesheets.clear-manual-import', $period))
        ->assertRedirect()
        ->assertSessionHas('success', '1 Manual/Imported timesheets cleared.');

    expect(CrewTimesheet::withTrashed()->find($timesheet->id)?->trashed())->toBeTrue();
});

test('timesheets from another company or period remain untouched', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    ['company' => $other] = makePayrollFixtures();
    grantClearTimesheetPermissions($user, $company);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $otherPeriod = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
    ]);
    $foreignPeriod = PayrollPeriod::factory()->for($other)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    $local = createCrewEmployeeWithContract($company, 'CLR-LOC', 100, 50, 25);
    $otherPeriodEmployee = createCrewEmployeeWithContract($company, 'CLR-OTH-P', 100, 50, 25);
    $foreignEmployee = createCrewEmployeeWithContract($other, 'CLR-FOR', 100, 50, 25);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $local->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
    ]);
    $otherPeriodTimesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $otherPeriodEmployee->id,
        'period_id' => $otherPeriod->id,
        'source' => CrewTimesheetSource::Manual,
    ]);
    $foreignTimesheet = CrewTimesheet::factory()->create([
        'company_id' => $other->id,
        'employee_id' => $foreignEmployee->id,
        'period_id' => $foreignPeriod->id,
        'source' => CrewTimesheetSource::Manual,
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->delete(route('payroll.crew-timesheets.clear-manual-import', $period))
        ->assertRedirect();

    expect(CrewTimesheet::query()->where('period_id', $period->id)->count())->toBe(0)
        ->and($otherPeriodTimesheet->fresh()->trashed())->toBeFalse()
        ->and($foreignTimesheet->fresh()->trashed())->toBeFalse();
});

test('office payroll periods cannot be cleared', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantClearTimesheetPermissions($user, $company);

    $office = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    createOfficeEmployeeWithContract($company, 'CLR-OFF', 5000, 1000, 500, 200);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->delete(route('payroll.crew-timesheets.clear-manual-import', $office))
        ->assertSessionHasErrors('period_id');

    expect(PayrollPeriod::query()->whereKey($office->id)->exists())->toBeTrue()
        ->and($office->fresh()->payroll_category)->toBe(PayrollCategory::Office);
});

test('non-draft periods cannot be cleared', function (PayrollPeriodStatus $status) {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantClearTimesheetPermissions($user, $company);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'status' => $status,
    ]);
    $employee = createCrewEmployeeWithContract($company, 'CLR-'.$status->value, 100, 50, 25);
    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->delete(route('payroll.crew-timesheets.clear-manual-import', $period))
        ->assertSessionHasErrors('period_id');

    expect($timesheet->fresh()->trashed())->toBeFalse();
})->with([
    PayrollPeriodStatus::Processing,
    PayrollPeriodStatus::Approved,
    PayrollPeriodStatus::Paid,
    PayrollPeriodStatus::Cancelled,
]);

test('unauthorised user receives 403 and cross-company request is 404', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    ['company' => $other] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $otherPeriod = PayrollPeriod::factory()->for($other)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->delete(route('payroll.crew-timesheets.clear-manual-import', $period))
        ->assertForbidden();

    grantClearTimesheetPermissions($user, $company);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->delete(route('payroll.crew-timesheets.clear-manual-import', $otherPeriod))
        ->assertNotFound();
});

test('clear soft-deletes, returns count, audits, and is idempotent', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantClearTimesheetPermissions($user, $company);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'excluded_employee_ids' => [999],
    ]);
    $employee = createCrewEmployeeWithContract($company, 'CLR-AUD', 100, 50, 25);
    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Import,
    ]);

    $result = app(ClearManualImportCrewTimesheets::class)->handle($period, $user, (int) $company->id);

    expect($result['cleared_count'])->toBe(1)
        ->and($result['cleared_timesheet_ids'])->toContain($timesheet->id)
        ->and(CrewTimesheet::withTrashed()->find($timesheet->id)?->trashed())->toBeTrue()
        ->and(CrewTimesheet::withTrashed()->whereKey($timesheet->id)->exists())->toBeTrue();

    $activity = Activity::query()
        ->where('description', 'Manual/Imported crew timesheets cleared')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($user->id)
        ->and($activity->properties->get('event'))->toBe('crew_timesheets_cleared')
        ->and($activity->properties->get('company_id'))->toBe($company->id)
        ->and($activity->properties->get('payroll_period_id'))->toBe($period->id)
        ->and($activity->properties->get('cleared_count'))->toBe(1)
        ->and($activity->properties->get('sources_cleared'))->toBe(['manual', 'import']);

    $second = app(ClearManualImportCrewTimesheets::class)->handle($period->fresh(), $user, (int) $company->id);

    expect($second['cleared_count'])->toBe(0)
        ->and($period->fresh()->excluded_employee_ids)->toBe([999]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->delete(route('payroll.crew-timesheets.clear-manual-import', $period))
        ->assertRedirect()
        ->assertSessionHas('success', 'No Manual or Imported timesheets were found to clear.');
});

test('manual and import data can be re-entered after clearing without duplicates and stay auto-approved', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantClearTimesheetPermissions($user, $company);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $manualEmployee = createCrewEmployeeWithContract($company, 'CLR-RE-M', 100, 50, 25);
    $importEmployee = createCrewEmployeeWithContract($company, 'CLR-RE-I', 100, 50, 25);

    $originalManual = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $manualEmployee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'onsite_days' => 4,
    ]);
    $originalImport = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $importEmployee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Import,
        'onsite_days' => 5,
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->delete(route('payroll.crew-timesheets.clear-manual-import', $period))
        ->assertRedirect();

    $restoredManual = app(UpsertCrewTimesheet::class)->handle($period, $manualEmployee, [
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-10',
        'onsite_days' => 10,
        'source' => CrewTimesheetSource::Manual,
    ], $user->id);

    $restoredImport = app(UpsertCrewTimesheet::class)->handle($period, $importEmployee, [
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-08',
        'onsite_days' => 8,
        'source' => CrewTimesheetSource::Import,
    ], $user->id);

    expect($restoredManual->id)->toBe($originalManual->id)
        ->and($restoredManual->trashed())->toBeFalse()
        ->and($restoredManual->approval_status)->toBe(CrewTimesheetApprovalStatus::Approved)
        ->and($restoredManual->approved_by)->toBe($user->id)
        ->and($restoredManual->source)->toBe(CrewTimesheetSource::Manual)
        ->and($restoredImport->id)->toBe($originalImport->id)
        ->and($restoredImport->source)->toBe(CrewTimesheetSource::Import)
        ->and($restoredImport->approval_status)->toBe(CrewTimesheetApprovalStatus::Approved)
        ->and($restoredImport->approved_by)->toBe($user->id)
        ->and(CrewTimesheet::withTrashed()
            ->where('employee_id', $manualEmployee->id)
            ->where('period_id', $period->id)
            ->count())->toBe(1)
        ->and(CrewTimesheet::withTrashed()
            ->where('employee_id', $importEmployee->id)
            ->where('period_id', $period->id)
            ->count())->toBe(1);
});

test('daily crew becomes not entered and monthly crew returns to default after clearing', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantClearTimesheetPermissions($user, $company);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $daily = createCrewEmployeeWithContract($company, 'CLR-DAY', 100, 50, 25);
    $monthly = createCrewMonthlyEmployeeWithContract($company, 'CLR-MON', 5000, 1000, 500, 250);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $daily->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'onsite_days' => 8,
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-08',
    ]);
    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $monthly->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Import,
        'unpaid_leave_days' => 2,
    ]);

    $before = app(BuildCrewPayrollGenerationPreview::class)->handle($period, (int) $company->id);
    expect($before->readyCount)->toBe(2);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->delete(route('payroll.crew-timesheets.clear-manual-import', $period))
        ->assertRedirect();

    $after = app(BuildCrewPayrollGenerationPreview::class)->handle($period, (int) $company->id);

    expect(CrewTimesheet::query()->where('period_id', $period->id)->count())->toBe(0)
        ->and($after->missingTimesheetCount)->toBe(1)
        ->and($after->readyEmployeeIds)->toContain($monthly->id)
        ->and($after->readyEmployeeIds)->not->toContain($daily->id)
        ->and($after->readyCount)->toBe(1);
});

test('clearable_timesheet_count includes all pages and show props refresh correctly', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantClearTimesheetPermissions($user, $company);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'excluded_employee_ids' => [42],
    ]);

    foreach (range(1, 5) as $index) {
        $employee = createCrewEmployeeWithContract($company, "CLR-CNT-{$index}", 100, 50, 25);
        CrewTimesheet::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'period_id' => $period->id,
            'source' => $index % 2 === 0
                ? CrewTimesheetSource::Import
                : CrewTimesheetSource::Manual,
            'onsite_days' => 3,
        ]);
    }

    expect(app(ClearableManualImportCrewTimesheetsQuery::class)->count($period, (int) $company->id))->toBe(5);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $period, 'per_page' => 2]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('clearable_timesheet_count', 5)
            ->where('permissions.clear_timesheets', true)
            ->where('period.excluded_employee_ids', [42]));

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->delete(route('payroll.crew-timesheets.clear-manual-import', $period))
        ->assertRedirect();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', $period))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('clearable_timesheet_count', 0)
            ->where('period.excluded_employee_ids', [42]));
});

test('empty clear request succeeds with zero count', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantClearTimesheetPermissions($user, $company);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    createCrewEmployeeWithContract($company, 'CLR-EMPTY', 100, 50, 25);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->delete(route('payroll.crew-timesheets.clear-manual-import', $period))
        ->assertRedirect()
        ->assertSessionHas('success', 'No Manual or Imported timesheets were found to clear.');
});
