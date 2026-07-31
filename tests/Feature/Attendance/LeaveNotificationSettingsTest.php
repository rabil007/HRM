<?php

use App\Models\Company;
use App\Models\CompanyLeaveApprovalSetting;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\User;
use App\Support\Attendance\LeaveNotificationSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{user: User, company: Company}
 */
function makeLeaveNotificationSettingsFixtures(): array
{
    $user = User::factory()->create(['status' => 'active']);
    $country = Country::query()->create([
        'code' => 'LN'.fake()->unique()->numerify('##'),
        'name' => 'Leave Notify Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'LN'.fake()->unique()->numerify('##'),
        'name' => 'Leave Notify Currency',
        'symbol' => 'L$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Leave Notify Co',
        'slug' => 'ln-'.fake()->unique()->numerify('####'),
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

/**
 * @return array{
 *     email_notifications_enabled: bool,
 *     notify_on_submission: bool,
 *     notify_on_update: bool,
 *     notify_next_approver: bool,
 *     notify_on_final_decision: bool,
 *     copy_deciding_approver: bool,
 * }
 */
function leaveNotificationPayload(array $overrides = []): array
{
    return array_merge([
        'email_notifications_enabled' => true,
        'notify_on_submission' => true,
        'notify_on_update' => true,
        'notify_next_approver' => true,
        'notify_on_final_decision' => true,
        'copy_deciding_approver' => true,
    ], $overrides);
}

test('settings page returns all notification settings', function () {
    ['user' => $user, 'company' => $company] = makeLeaveNotificationSettingsFixtures();

    $settings = CompanyLeaveApprovalSetting::forCompany($company->id);
    $settings->update([
        'email_notifications_enabled' => false,
        'notify_on_submission' => false,
        'notify_on_update' => true,
        'notify_next_approver' => false,
        'notify_on_final_decision' => true,
        'copy_deciding_approver' => false,
    ]);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-approval-settings.view',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get('/attendance/leave-approval-settings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('attendance/leave-approval-settings')
            ->where('settings.email_notifications_enabled', false)
            ->where('settings.notify_on_submission', false)
            ->where('settings.notify_on_update', true)
            ->where('settings.notify_next_approver', false)
            ->where('settings.notify_on_final_decision', true)
            ->where('settings.copy_deciding_approver', false)
        );
});

test('notification settings default to true when no row exists', function () {
    ['company' => $company] = makeLeaveNotificationSettingsFixtures();

    expect(CompanyLeaveApprovalSetting::query()->where('company_id', $company->id)->exists())->toBeFalse();

    $settings = LeaveNotificationSettings::forCompany($company->id);

    expect($settings->emailNotificationsEnabled())->toBeTrue()
        ->and($settings->shouldNotifyOnSubmission())->toBeTrue()
        ->and($settings->shouldNotifyOnUpdate())->toBeTrue()
        ->and($settings->shouldNotifyNextApprover())->toBeTrue()
        ->and($settings->shouldNotifyOnFinalDecision())->toBeTrue()
        ->and($settings->shouldCopyDecidingApprover())->toBeTrue()
        ->and(CompanyLeaveApprovalSetting::query()->where('company_id', $company->id)->exists())->toBeFalse();
});

test('existing settings rows receive true defaults after migration', function () {
    ['company' => $company] = makeLeaveNotificationSettingsFixtures();

    $migration = '2026_07_30_094321_add_email_notification_controls_to_company_leave_approval_settings_table';
    $path = 'database/migrations/'.$migration.'.php';

    Artisan::call('migrate:rollback', [
        '--force' => true,
        '--path' => $path,
    ]);

    expect(Schema::hasColumn('company_leave_approval_settings', 'email_notifications_enabled'))->toBeFalse();

    $id = DB::table('company_leave_approval_settings')->insertGetId([
        'company_id' => $company->id,
        'default_hr_approver_employee_id' => null,
        'fallback_approver_employee_id' => null,
        'updated_by' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Artisan::call('migrate', [
        '--force' => true,
        '--path' => $path,
    ]);

    $row = DB::table('company_leave_approval_settings')->where('id', $id)->first();

    expect(Schema::hasColumn('company_leave_approval_settings', 'email_notifications_enabled'))->toBeTrue()
        ->and((bool) $row->email_notifications_enabled)->toBeTrue()
        ->and((bool) $row->notify_on_submission)->toBeTrue()
        ->and((bool) $row->notify_on_update)->toBeTrue()
        ->and((bool) $row->notify_next_approver)->toBeTrue()
        ->and((bool) $row->notify_on_final_decision)->toBeTrue()
        ->and((bool) $row->copy_deciding_approver)->toBeTrue();
});

test('authorised user can update all notification switches', function () {
    ['user' => $user, 'company' => $company] = makeLeaveNotificationSettingsFixtures();

    grantCompanyPermissions($user, $company, [
        'attendance.leave-approval-settings.view',
        'attendance.leave-approval-settings.update',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->put('/attendance/leave-approval-settings', array_merge([
            'default_hr_approver_employee_id' => null,
            'fallback_approver_employee_id' => null,
        ], leaveNotificationPayload([
            'email_notifications_enabled' => false,
            'notify_on_submission' => false,
            'notify_on_update' => true,
            'notify_next_approver' => false,
            'notify_on_final_decision' => true,
            'copy_deciding_approver' => false,
        ])))
        ->assertRedirect(route('attendance.leave-approval-settings.edit'))
        ->assertSessionHas('success');

    $settings = CompanyLeaveApprovalSetting::query()->where('company_id', $company->id)->firstOrFail();

    expect($settings->email_notifications_enabled)->toBeFalse()
        ->and($settings->notify_on_submission)->toBeFalse()
        ->and($settings->notify_on_update)->toBeTrue()
        ->and($settings->notify_next_approver)->toBeFalse()
        ->and($settings->notify_on_final_decision)->toBeTrue()
        ->and($settings->copy_deciding_approver)->toBeFalse()
        ->and((int) $settings->updated_by)->toBe((int) $user->id);
});

test('user without update permission receives 403', function () {
    ['user' => $user, 'company' => $company] = makeLeaveNotificationSettingsFixtures();

    grantCompanyPermissions($user, $company, [
        'attendance.leave-approval-settings.view',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->put('/attendance/leave-approval-settings', array_merge([
            'default_hr_approver_employee_id' => null,
            'fallback_approver_employee_id' => null,
        ], leaveNotificationPayload()))
        ->assertForbidden();
});

test('cross-company employee and settings ids cannot change another company', function () {
    ['user' => $user, 'company' => $company] = makeLeaveNotificationSettingsFixtures();

    $otherCountry = Country::query()->create([
        'code' => 'OX'.fake()->unique()->numerify('##'),
        'name' => 'Other Notify Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $otherCurrency = Currency::query()->create([
        'code' => 'OX'.fake()->unique()->numerify('##'),
        'name' => 'Other Notify Currency',
        'symbol' => 'O$',
        'is_active' => true,
    ]);
    $otherCompany = Company::query()->create([
        'name' => 'Other Notify Co',
        'slug' => 'ox-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $otherCountry->id,
        'currency_id' => $otherCurrency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $foreignEmployee = Employee::factory()->forCompany($otherCompany)->create(['status' => 'active']);
    $foreignSettings = CompanyLeaveApprovalSetting::forCompany($otherCompany->id);
    $foreignSettings->update(leaveNotificationPayload([
        'email_notifications_enabled' => true,
        'notify_on_submission' => true,
    ]));

    grantCompanyPermissions($user, $company, [
        'attendance.leave-approval-settings.view',
        'attendance.leave-approval-settings.update',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->put('/attendance/leave-approval-settings', array_merge([
            'id' => $foreignSettings->id,
            'company_id' => $otherCompany->id,
            'default_hr_approver_employee_id' => $foreignEmployee->id,
            'fallback_approver_employee_id' => null,
        ], leaveNotificationPayload([
            'email_notifications_enabled' => false,
            'notify_on_submission' => false,
        ])))
        ->assertSessionHasErrors('default_hr_approver_employee_id');

    expect(CompanyLeaveApprovalSetting::query()->where('company_id', $otherCompany->id)->value('email_notifications_enabled'))
        ->toBeTrue()
        ->and(CompanyLeaveApprovalSetting::query()->where('company_id', $company->id)->exists())
        ->toBeFalse();
});

test('notification settings are tenant isolated', function () {
    ['user' => $user, 'company' => $company] = makeLeaveNotificationSettingsFixtures();

    $otherCountry = Country::query()->create([
        'code' => 'TI'.fake()->unique()->numerify('##'),
        'name' => 'Tenant Isolate Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $otherCurrency = Currency::query()->create([
        'code' => 'TI'.fake()->unique()->numerify('##'),
        'name' => 'Tenant Isolate Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);
    $otherCompany = Company::query()->create([
        'name' => 'Tenant Isolate Co',
        'slug' => 'ti-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $otherCountry->id,
        'currency_id' => $otherCurrency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    CompanyLeaveApprovalSetting::forCompany($otherCompany->id)->update(leaveNotificationPayload([
        'email_notifications_enabled' => false,
    ]));

    grantCompanyPermissions($user, $company, [
        'attendance.leave-approval-settings.view',
        'attendance.leave-approval-settings.update',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->put('/attendance/leave-approval-settings', array_merge([
            'default_hr_approver_employee_id' => null,
            'fallback_approver_employee_id' => null,
            'company_id' => $otherCompany->id,
        ], leaveNotificationPayload([
            'email_notifications_enabled' => true,
            'notify_on_submission' => false,
        ])))
        ->assertRedirect(route('attendance.leave-approval-settings.edit'));

    expect(CompanyLeaveApprovalSetting::query()->where('company_id', $company->id)->value('notify_on_submission'))
        ->toBeFalse()
        ->and(CompanyLeaveApprovalSetting::query()->where('company_id', $otherCompany->id)->value('email_notifications_enabled'))
        ->toBeFalse();
});

test('activity logging records notification setting changes', function () {
    ['user' => $user, 'company' => $company] = makeLeaveNotificationSettingsFixtures();

    grantCompanyPermissions($user, $company, [
        'attendance.leave-approval-settings.view',
        'attendance.leave-approval-settings.update',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->put('/attendance/leave-approval-settings', array_merge([
            'default_hr_approver_employee_id' => null,
            'fallback_approver_employee_id' => null,
        ], leaveNotificationPayload([
            'email_notifications_enabled' => false,
            'notify_on_final_decision' => false,
        ])))
        ->assertRedirect();

    $settings = CompanyLeaveApprovalSetting::query()->where('company_id', $company->id)->firstOrFail();

    $activity = Activity::query()
        ->where('company_id', $company->id)
        ->where('subject_type', CompanyLeaveApprovalSetting::class)
        ->where('subject_id', $settings->id)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and((int) $activity->causer_id)->toBe((int) $user->id)
        ->and((int) $activity->company_id)->toBe((int) $company->id)
        ->and($activity->attribute_changes->get('attributes'))->toHaveKey('email_notifications_enabled')
        ->and($activity->attribute_changes->get('old'))->toHaveKey('email_notifications_enabled')
        ->and($activity->attribute_changes->get('attributes')['email_notifications_enabled'])->toBeFalse()
        ->and($activity->attribute_changes->get('old')['email_notifications_enabled'])->toBeTrue()
        ->and($activity->attribute_changes->get('attributes'))->toHaveKey('updated_by')
        ->and((int) $activity->attribute_changes->get('attributes')['updated_by'])->toBe((int) $user->id);
});
