<?php

use App\Enums\LeaveApprovalApproverType;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\LeaveApprovalPolicy;
use App\Models\LeaveApprovalPolicyStep;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\Actions\SubmitLeaveRequestWithApprovals;
use App\Support\Attendance\LeaveBalanceManager;
use Illuminate\Support\Facades\DB;

/**
 * @return array{user: User, company: Company}
 */
function makeStepIdentityFixtures(): array
{
    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'SI'.fake()->unique()->numerify('##'),
        'name' => 'Step Identityland',
        'dial_code' => '+990',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'SI'.fake()->unique()->numerify('##'),
        'name' => 'Step Identity Currency',
        'symbol' => 'S$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Step Identity Co',
        'slug' => 'si-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['user' => $user, 'company' => $company];
}

test('policy step reorder preserves conceptual step ids and provenance', function () {
    ['user' => $user, 'company' => $company] = makeStepIdentityFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-approval-policies.view',
        'attendance.leave-approval-policies.update',
        'attendance.leave-approval-policies.create',
    ]);

    $hr = makeActionableApprover($company);
    configureCompanyLeaveApprovalSettings($company, $hr['employee']);

    $policy = LeaveApprovalPolicy::factory()
        ->forCompany($company)
        ->default()
        ->withSteps([
            ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
            ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
            ['type' => LeaveApprovalApproverType::ParentManager, 'required' => false],
        ])
        ->create();

    $managerStep = $policy->steps()->where('sequence', 1)->firstOrFail();
    $hrStep = $policy->steps()->where('sequence', 2)->firstOrFail();
    $optionalStep = $policy->steps()->where('sequence', 3)->firstOrFail();

    $this->put("/attendance/leave-approval-policies/{$policy->id}", [
        'name' => $policy->name,
        'description' => $policy->description,
        'status' => $policy->status,
        'is_default' => true,
        'steps' => [
            [
                'id' => $hrStep->id,
                'approver_type' => LeaveApprovalApproverType::HrApprover->value,
                'approver_employee_id' => null,
                'is_required' => true,
            ],
            [
                'id' => $managerStep->id,
                'approver_type' => LeaveApprovalApproverType::DepartmentManager->value,
                'approver_employee_id' => null,
                'is_required' => true,
            ],
            [
                'id' => $optionalStep->id,
                'approver_type' => LeaveApprovalApproverType::ParentManager->value,
                'approver_employee_id' => null,
                'is_required' => false,
            ],
        ],
    ])->assertRedirect(route('attendance.leave-approval-policies.index'));

    $ordered = $policy->fresh()->steps()->orderBy('sequence')->get();
    expect($ordered)->toHaveCount(3)
        ->and((int) $ordered[0]->id)->toBe((int) $hrStep->id)
        ->and((int) $ordered[1]->id)->toBe((int) $managerStep->id)
        ->and((int) $ordered[2]->id)->toBe((int) $optionalStep->id);

    $extraApprover = makeActionableApprover($company, [
        'name' => 'Extra Approver',
        'work_email' => 'extra-approver@example.com',
    ]);

    $this->put("/attendance/leave-approval-policies/{$policy->id}", [
        'name' => $policy->name,
        'description' => 'Updated description',
        'status' => $policy->status,
        'is_default' => true,
        'steps' => [
            [
                'id' => $hrStep->id,
                'approver_type' => LeaveApprovalApproverType::HrApprover->value,
                'approver_employee_id' => null,
                'is_required' => false,
            ],
            [
                'id' => $managerStep->id,
                'approver_type' => LeaveApprovalApproverType::DepartmentManager->value,
                'approver_employee_id' => null,
                'is_required' => true,
            ],
            [
                'approver_type' => LeaveApprovalApproverType::SpecificEmployee->value,
                'approver_employee_id' => $extraApprover['employee']->id,
                'is_required' => true,
            ],
        ],
    ])->assertRedirect(route('attendance.leave-approval-policies.index'));

    $after = $policy->fresh()->steps()->orderBy('sequence')->get();
    expect($after)->toHaveCount(3)
        ->and((int) $after[0]->id)->toBe((int) $hrStep->id)
        ->and((bool) $after[0]->is_required)->toBeFalse()
        ->and((int) $after[1]->id)->toBe((int) $managerStep->id)
        ->and(LeaveApprovalPolicyStep::query()->whereKey($optionalStep->id)->exists())->toBeFalse()
        ->and((int) $after[2]->id)->not->toBe((int) $optionalStep->id)
        ->and($after[2]->approver_type->value)->toBe(LeaveApprovalApproverType::SpecificEmployee->value);

    $managed = makeManagedDepartment($company);
    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $managed['department']->id,
    ]);
    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 20,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, 2026);

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $company->id,
        attributes: [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-01',
            'reason' => 'Provenance',
        ],
        notify: false,
    );

    $snapshots = LeaveRequestApproval::query()
        ->where('leave_request_id', $leaveRequest->id)
        ->orderBy('sequence')
        ->get();

    expect((int) $snapshots[0]->policy_step_id)->toBe((int) $hrStep->id)
        ->and((int) $snapshots[1]->policy_step_id)->toBe((int) $managerStep->id)
        ->and((int) $snapshots[2]->policy_step_id)->toBe((int) $after[2]->id);
});

test('foreign and duplicate policy step ids are rejected without mutating the policy', function () {
    ['user' => $user, 'company' => $company] = makeStepIdentityFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-approval-policies.update',
        'attendance.leave-approval-policies.view',
        'attendance.leave-approval-policies.create',
    ]);

    $policy = LeaveApprovalPolicy::factory()
        ->forCompany($company)
        ->withSteps([
            ['type' => LeaveApprovalApproverType::DepartmentManager],
            ['type' => LeaveApprovalApproverType::HrApprover],
        ])
        ->create(['is_default' => false]);

    $first = $policy->steps()->where('sequence', 1)->firstOrFail();
    $second = $policy->steps()->where('sequence', 2)->firstOrFail();
    $before = $policy->steps()->orderBy('sequence')->get(['id', 'sequence', 'approver_type', 'is_required'])->toArray();

    $otherCompany = Company::query()->create([
        'name' => 'Foreign Step Co',
        'slug' => 'fs-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $company->country_id,
        'currency_id' => $company->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    $foreignPolicy = LeaveApprovalPolicy::factory()
        ->forCompany($otherCompany)
        ->withSteps([['type' => LeaveApprovalApproverType::DepartmentManager]])
        ->create();
    $foreignStep = $foreignPolicy->steps()->firstOrFail();

    $this->put("/attendance/leave-approval-policies/{$policy->id}", [
        'name' => $policy->name,
        'description' => $policy->description,
        'status' => $policy->status,
        'is_default' => false,
        'steps' => [
            [
                'id' => $foreignStep->id,
                'approver_type' => LeaveApprovalApproverType::DepartmentManager->value,
                'approver_employee_id' => null,
                'is_required' => true,
            ],
        ],
    ])->assertSessionHasErrors('steps.0.id');

    $sibling = LeaveApprovalPolicy::factory()
        ->forCompany($company)
        ->withSteps([['type' => LeaveApprovalApproverType::HrApprover]])
        ->create(['is_default' => false]);
    $siblingStep = $sibling->steps()->firstOrFail();

    $this->put("/attendance/leave-approval-policies/{$policy->id}", [
        'name' => $policy->name,
        'description' => $policy->description,
        'status' => $policy->status,
        'is_default' => false,
        'steps' => [
            [
                'id' => $siblingStep->id,
                'approver_type' => LeaveApprovalApproverType::HrApprover->value,
                'approver_employee_id' => null,
                'is_required' => true,
            ],
        ],
    ])->assertSessionHasErrors('steps.0.id');

    $this->put("/attendance/leave-approval-policies/{$policy->id}", [
        'name' => $policy->name,
        'description' => $policy->description,
        'status' => $policy->status,
        'is_default' => false,
        'steps' => [
            [
                'id' => $first->id,
                'approver_type' => LeaveApprovalApproverType::DepartmentManager->value,
                'approver_employee_id' => null,
                'is_required' => true,
            ],
            [
                'id' => $first->id,
                'approver_type' => LeaveApprovalApproverType::HrApprover->value,
                'approver_employee_id' => null,
                'is_required' => true,
            ],
        ],
    ])->assertSessionHasErrors('steps.1.id');

    $this->post('/attendance/leave-approval-policies', [
        'name' => 'Create with ids',
        'description' => null,
        'is_default' => false,
        'status' => 'active',
        'steps' => [
            [
                'id' => $second->id,
                'approver_type' => LeaveApprovalApproverType::DepartmentManager->value,
                'approver_employee_id' => null,
                'is_required' => true,
            ],
        ],
    ])->assertSessionHasErrors('steps.0.id');

    expect($policy->fresh()->steps()->orderBy('sequence')->get(['id', 'sequence', 'approver_type', 'is_required'])->toArray())
        ->toBe($before);
});
