<?php

use App\Enums\LeaveApprovalApproverType;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\Actions\SubmitLeaveRequestWithApprovals;
use App\Support\Attendance\CalculateLeaveRequestDays;
use Illuminate\Support\Facades\DB;

/**
 * @return array{company: Company}
 */
function makeProvenanceCompany(): array
{
    $country = Country::query()->create([
        'code' => 'PV'.fake()->unique()->numerify('##'),
        'name' => 'Provenanceland',
        'dial_code' => '+992',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'PV'.fake()->unique()->numerify('##'),
        'name' => 'Provenance Currency',
        'symbol' => 'P$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Provenance Co',
        'slug' => 'pv-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    return ['company' => $company];
}

test('approval snapshot stores policy provenance and survives policy step recreation', function () {
    ['company' => $company] = makeProvenanceCompany();
    $managed = makeManagedDepartment($company);
    $policy = ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
    ]);
    $policy->update(['name' => 'Original Policy Name']);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $managed['department']->id,
    ]);
    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active', 'days_per_year' => 30]);

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $company->id,
        attributes: [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'total_days' => app(CalculateLeaveRequestDays::class)('2026-06-01', '2026-06-02'),
            'reason' => 'Trip',
        ],
        notify: false,
    );

    $approval = $leaveRequest->approvals->first();
    expect($approval)->not->toBeNull()
        ->and($approval->policy_id)->toBe($policy->id)
        ->and($approval->policy_name)->toBe('Original Policy Name')
        ->and($approval->policy_step_label)->toContain('Department Manager');

    $originalStepId = $approval->policy_step_id;
    $originalPolicyId = $approval->policy_id;
    $originalPolicyName = $approval->policy_name;
    $originalLabel = $approval->policy_step_label;

    // Diff-based policy step sync preserves step IDs for unchanged positions.
    // Snapshot provenance fields on the approval row must remain frozen.
    $user = User::factory()->create();
    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    grantCompanyPermissions($user, $company, [
        'attendance.leave-approval-policies.update',
    ]);

    $this->actingAs($user)
        ->put("/attendance/leave-approval-policies/{$policy->id}", [
            'name' => 'Renamed Policy',
            'description' => null,
            'is_default' => true,
            'status' => 'active',
            'steps' => [
                [
                    'approver_type' => LeaveApprovalApproverType::DepartmentManager->value,
                    'is_required' => true,
                ],
            ],
        ])
        ->assertRedirect();

    $approval->refresh();

    expect($approval->policy_step_id)->toBe($originalStepId)
        ->and($approval->policy_id)->toBe($originalPolicyId)
        ->and($approval->policy_name)->toBe($originalPolicyName)
        ->and($approval->policy_step_label)->toBe($originalLabel)
        ->and($originalStepId)->not->toBeNull()
        ->and($policy->fresh()->name)->toBe('Renamed Policy');
});

test('optional snapshot metadata backfill never overwrites existing provenance', function () {
    ['company' => $company] = makeProvenanceCompany();
    $managed = makeManagedDepartment($company);
    $policy = ensureDefaultLeaveApprovalPolicy($company);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $managed['department']->id,
    ]);
    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active']);

    $leaveRequest = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-02',
        'total_days' => 2,
        'status' => 'pending',
    ]);

    $step = $policy->steps()->first();

    $approval = LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $leaveRequest->id,
        'sequence' => 1,
        'approver_type' => LeaveApprovalApproverType::DepartmentManager,
        'approver_employee_id' => $managed['manager']->id,
        'approver_user_id' => $managed['managerUser']->id,
        'policy_step_id' => $step->id,
        'policy_id' => null,
        'policy_name' => 'Keep Me',
        'policy_step_label' => null,
    ]);

    $this->artisan('leave-approvals:backfill', [
        '--company' => $company->id,
        '--request' => $leaveRequest->id,
        '--fill-snapshot-metadata' => true,
    ])->assertSuccessful();

    $approval->refresh();

    expect($approval->policy_name)->toBe('Keep Me')
        ->and($approval->policy_id)->toBe($policy->id)
        ->and($approval->policy_step_label)->not->toBeNull();
});
