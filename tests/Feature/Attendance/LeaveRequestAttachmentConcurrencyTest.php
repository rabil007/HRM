<?php

use App\Enums\LeaveApprovalApproverType;
use App\Enums\LeaveRequestApprovalStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\Actions\SubmitLeaveRequestWithApprovals;
use App\Support\Attendance\Actions\UpdateLeaveRequestWithApprovals;
use App\Support\Attendance\LeaveBalanceManager;
use App\Support\Attendance\LeaveRequestAttachments;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * @return array{
 *     company: Company,
 *     employee: Employee,
 *     leaveType: LeaveType,
 *     managerUser: User,
 *     owner: User,
 * }
 */
function makeAttachmentConcurrencyContext(): array
{
    $owner = User::factory()->create(['status' => 'active']);
    $country = Country::query()->create([
        'code' => 'AC'.fake()->unique()->numerify('##'),
        'name' => 'Attachment Concurrencyland',
        'dial_code' => '+981',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'AC'.fake()->unique()->numerify('##'),
        'name' => 'Attachment Currency',
        'symbol' => 'A$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Attachment Concurrency Co',
        'slug' => 'ac-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $owner->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $managed = makeManagedDepartment($company);
    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $managed['department']->id,
        'user_id' => $owner->id,
    ]);
    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, 2026);

    return [
        'company' => $company,
        'employee' => $employee,
        'leaveType' => $leaveType,
        'managerUser' => $managed['managerUser'],
        'owner' => $owner,
    ];
}

test('successful attachment replacement removes previous file from storage', function () {
    Storage::fake('local');

    $context = makeAttachmentConcurrencyContext();

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'total_days' => 2,
            'reason' => 'With attachment',
        ],
        reserveBalance: true,
        notify: false,
    );

    $originalFile = UploadedFile::fake()->create('original.pdf', 100, 'application/pdf');
    $storedOriginal = app(LeaveRequestAttachments::class)->store(
        $originalFile,
        (int) $context['company']->id,
        (int) $leaveRequest->id,
    );
    $originalPath = $storedOriginal[0]['path'];
    $leaveRequest->forceFill(['attachments' => $storedOriginal])->save();

    Storage::disk('local')->assertExists($originalPath);

    $replacementFile = UploadedFile::fake()->create('replacement.pdf', 120, 'application/pdf');

    app(UpdateLeaveRequestWithApprovals::class)->handle(
        $leaveRequest,
        (int) $context['company']->id,
        [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-12',
            'reason' => 'Updated attachment',
        ],
        newAttachment: $replacementFile,
        actor: $context['owner'],
    );

    $leaveRequest->refresh();
    $newPath = $leaveRequest->attachments[0]['path'] ?? null;

    Storage::disk('local')->assertMissing($originalPath);
    Storage::disk('local')->assertExists((string) $newPath);
    expect($newPath)->not->toBe($originalPath);
});

test('failed update after approval started removes only the new upload and preserves committed attachment', function () {
    Storage::fake('local');

    $context = makeAttachmentConcurrencyContext();

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'total_days' => 2,
            'reason' => 'With attachment',
        ],
        reserveBalance: true,
        notify: false,
    );

    $committedFile = UploadedFile::fake()->create('committed.pdf', 100, 'application/pdf');
    $storedCommitted = app(LeaveRequestAttachments::class)->store(
        $committedFile,
        (int) $context['company']->id,
        (int) $leaveRequest->id,
    );
    $committedPath = $storedCommitted[0]['path'];
    $leaveRequest->forceFill(['attachments' => $storedCommitted])->save();

    LeaveRequestApproval::query()
        ->where('leave_request_id', $leaveRequest->id)
        ->where('sequence', 1)
        ->update([
            'status' => LeaveRequestApprovalStatus::Approved->value,
            'acted_at' => now(),
        ]);

    $failedUpload = UploadedFile::fake()->create('failed-upload.pdf', 90, 'application/pdf');

    Storage::disk('local')->assertExists($committedPath);

    expect(fn () => app(UpdateLeaveRequestWithApprovals::class)->handle(
        $leaveRequest,
        (int) $context['company']->id,
        [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-10',
            'reason' => 'Should fail',
        ],
        newAttachment: $failedUpload,
        actor: $context['owner'],
    ))->toThrow(ValidationException::class);

    Storage::disk('local')->assertExists($committedPath);

    $orphanedUploads = collect(Storage::disk('local')->allFiles())
        ->filter(fn (string $path): bool => str_contains($path, 'failed-upload.pdf'));

    expect($orphanedUploads)->toBeEmpty();

    expect($leaveRequest->fresh()->attachments[0]['path'] ?? null)->toBe($committedPath);
});
