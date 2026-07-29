<?php

use App\Enums\LeaveApprovalApproverType;
use App\Mail\LeaveRequestSubmittedMail;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\LeaveBalanceManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * @return array{user: User, company: Company}
 */
function makeLeaveEmailFixtures(): array
{
    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'EM'.fake()->unique()->numerify('##'),
        'name' => 'Emailland',
        'dial_code' => '+993',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'EM'.fake()->unique()->numerify('##'),
        'name' => 'Email Currency',
        'symbol' => 'E$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Email Co',
        'slug' => 'em-'.fake()->unique()->numerify('####'),
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

test('workflow submission puts template to and cc presets into deduplicated FYI CC', function () {
    ['user' => $user, 'company' => $company] = makeLeaveEmailFixtures();
    $managed = makeManagedDepartment($company);
    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $managed['department']->id,
        'user_id' => $user->id,
    ]);
    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active', 'days_per_year' => 30]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, 2026);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.create',
        'attendance.leave-requests.view',
    ]);

    EmailTemplate::query()->updateOrCreate(
        ['slug' => 'leave_request_submitted'],
        [
            'name' => 'Leave request submitted',
            'subject' => 'Leave request',
            'body_html' => 'Hello',
            'enabled' => true,
            'to_preset' => 'hr@example.com, dept-manager@example.com, HR@example.com',
            'cc_preset' => 'fyi@example.com, hr@example.com',
            'include_company_footer' => false,
        ],
    );

    Mail::fake();

    $this->actingAs($user)
        ->post('/attendance/leave-requests', [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'reason' => 'Trip',
        ])
        ->assertRedirect();

    Mail::assertQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail) {
        $pending = 'dept-manager@example.com';

        expect($mail->hasTo($pending))->toBeTrue()
            ->and($mail->hasCc('hr@example.com'))->toBeTrue()
            ->and($mail->hasCc('fyi@example.com'))->toBeTrue()
            ->and($mail->hasCc($pending))->toBeFalse();

        return true;
    });
});

test('normal leave submission still notifies the first pending approver', function () {
    ['user' => $user, 'company' => $company] = makeLeaveEmailFixtures();
    $managed = makeManagedDepartment($company);
    ensureDefaultLeaveApprovalPolicy($company);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $managed['department']->id,
        'user_id' => $user->id,
    ]);
    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active', 'days_per_year' => 30]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, 2026);

    grantCompanyPermissions($user, $company, ['attendance.leave-requests.create']);

    EmailTemplate::query()->updateOrCreate(
        ['slug' => 'leave_request_submitted'],
        [
            'name' => 'Leave request submitted',
            'subject' => 'Leave request',
            'body_html' => 'Hello',
            'enabled' => true,
            'to_preset' => 'legacy@example.com',
            'cc_preset' => null,
            'include_company_footer' => false,
        ],
    );

    Mail::fake();

    $this->actingAs($user)
        ->post('/attendance/leave-requests', [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'reason' => 'Trip',
        ])
        ->assertRedirect();

    Mail::assertQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail) {
        return $mail->hasTo('dept-manager@example.com');
    });
});
