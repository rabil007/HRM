<?php

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\Dashboard\DashboardAnalytics;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function seedDashboardAnalyticsFixtures(int $employeeCount = 8, int $attendanceDays = 7): array
{
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Asia/Dubai'));

    ['company' => $company, 'branch' => $branch, 'employee' => $seedEmployee, 'passportType' => $passportType] = makeDocumentFixtures();
    $company->update(['timezone' => 'Asia/Dubai']);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Operations',
        'status' => 'active',
    ]);

    Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'Yard',
        'code' => 'YD',
        'status' => 'active',
    ]);

    $linkedUser = User::factory()->create();

    Employee::factory()->count(max(0, $employeeCount - 4))->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $department->id,
        'branch_id' => $branch->id,
        'created_at' => now()->subMonths(2),
    ]);

    Employee::factory()->forCompany($company)->create([
        'status' => 'inactive',
        'department_id' => $department->id,
        'created_at' => now()->subMonths(4),
    ]);

    Employee::factory()->forCompany($company)->create([
        'status' => 'on_leave',
        'created_at' => now()->subMonths(1),
    ]);

    Employee::factory()->forCompany($company)->create([
        'status' => 'terminated',
        'created_at' => now()->subMonths(3),
    ]);

    Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $linkedUser->id,
        'department_id' => $department->id,
        'branch_id' => $branch->id,
        'created_at' => now()->startOfMonth()->addDay(),
    ]);

    $employees = Employee::query()->where('company_id', $company->id)->get();

    foreach ($employees->take(min($employeeCount, $employees->count())) as $index => $employee) {
        AttendanceRecord::query()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'date' => '2026-06-15',
            'clock_in' => '2026-06-15 09:00:00',
            'clock_out' => $index % 2 === 0 ? '2026-06-15 17:00:00' : null,
            'status' => $index === 0
                ? AttendanceRecord::STATUS_LATE
                : ($index === 1 ? AttendanceRecord::STATUS_ABSENT : AttendanceRecord::STATUS_PRESENT),
            'source' => AttendanceRecord::SOURCE_MANUAL,
        ]);

        for ($day = 1; $day <= $attendanceDays; $day++) {
            AttendanceRecord::query()->create([
                'company_id' => $company->id,
                'employee_id' => $employee->id,
                'date' => Carbon::parse('2026-06-15')->subDays($day)->toDateString(),
                'clock_in' => '2026-06-'.str_pad((string) max(1, 15 - $day), 2, '0', STR_PAD_LEFT).' 09:00:00',
                'clock_out' => '2026-06-'.str_pad((string) max(1, 15 - $day), 2, '0', STR_PAD_LEFT).' 17:00:00',
                'status' => AttendanceRecord::STATUS_PRESENT,
                'source' => AttendanceRecord::SOURCE_MANUAL,
            ]);
        }

        EmployeeDocument::query()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'document_type_id' => $passportType->id,
            'type' => 'passport',
            'title' => 'Passport',
            'file_path' => 'documents/perf-'.$employee->id.'.pdf',
            'expiry_date' => $index % 3 === 0 ? now()->subDay()->toDateString() : now()->addDays(10)->toDateString(),
            'status' => 'valid',
            'created_at' => now()->subMonths($index % 5)->addDays(2),
        ]);
    }

    return compact('company', 'branch', 'department', 'seedEmployee', 'passportType');
}

function countDashboardAnalyticsQueries(int $companyId): int
{
    DashboardAnalytics::forgetCompany($companyId);
    DB::flushQueryLog();
    DB::enableQueryLog();
    app(DashboardAnalytics::class)->forCompany($companyId);

    return count(DB::getQueryLog());
}

test('dashboard analytics query count stays bounded as fixture size grows', function () {
    ['company' => $smallCompany] = seedDashboardAnalyticsFixtures(6, 7);
    $smallCount = countDashboardAnalyticsQueries($smallCompany->id);

    ['company' => $largeCompany] = seedDashboardAnalyticsFixtures(24, 7);
    $largeCount = countDashboardAnalyticsQueries($largeCompany->id);

    expect($smallCount)->toBeLessThan(25)
        ->and($largeCount)->toBeLessThanOrEqual($smallCount + 2);

    Carbon::setTestNow();
});

test('dashboard attendance weekly trends use a single grouped range query', function () {
    ['company' => $company] = seedDashboardAnalyticsFixtures(5, 7);
    DashboardAnalytics::forgetCompany($company->id);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $payload = app(DashboardAnalytics::class)->forCompany($company->id);
    $queries = collect(DB::getQueryLog());

    $attendanceDateQueries = $queries->filter(
        fn (array $query): bool => str_contains(strtolower($query['query']), 'attendance_records')
            && str_contains(strtolower($query['query']), 'group by')
            && str_contains(strtolower($query['query']), 'date'),
    );

    expect($attendanceDateQueries)->toHaveCount(1)
        ->and($payload['attendance_analytics']['weekly_trends'])->toHaveCount(7)
        ->and($payload['attendance_analytics']['weekly_trends'][0]['day'])->toBeString()
        ->and($payload['attendance_analytics']['weekly_trends'][6]['day'])->toBeString();

    Carbon::setTestNow();
});

test('dashboard workforce trends use bounded queries and cumulative headcount', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Asia/Dubai'));
    ['company' => $company, 'passportType' => $passportType] = makeDocumentFixtures();

    Employee::query()->where('company_id', $company->id)->update([
        'created_at' => now()->subMonths(8),
    ]);

    Employee::factory()->count(2)->forCompany($company)->create([
        'status' => 'active',
        'created_at' => now()->subMonths(2)->startOfMonth()->addDays(3),
    ]);
    Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'created_at' => now()->startOfMonth()->addDay(),
    ]);

    $employee = Employee::query()->where('company_id', $company->id)->first();
    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'passport',
        'title' => 'Passport',
        'file_path' => 'documents/trend.pdf',
        'expiry_date' => now()->addMonth()->toDateString(),
        'status' => 'valid',
        'created_at' => now()->subMonths(2)->startOfMonth()->addDays(5),
    ]);

    DashboardAnalytics::forgetCompany($company->id);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $trends = app(DashboardAnalytics::class)->workforceTrends($company->id);
    $queryCount = count(DB::getQueryLog());

    expect($trends)->toHaveCount(6)
        ->and($queryCount)->toBeLessThanOrEqual(4)
        ->and($trends[5]['month'])->toBe('Jun')
        ->and($trends[5]['new_hires'])->toBe(1)
        ->and($trends[3]['new_hires'])->toBe(2)
        ->and($trends[3]['documents'])->toBe(1)
        ->and($trends[5]['headcount'])->toBeGreaterThanOrEqual($trends[0]['headcount']);

    for ($i = 1; $i < 6; $i++) {
        expect($trends[$i]['headcount'])->toBeGreaterThanOrEqual($trends[$i - 1]['headcount']);
    }

    Carbon::setTestNow();
});

test('dashboard today attendance summary uses one aggregate query', function () {
    ['company' => $company] = seedDashboardAnalyticsFixtures(5, 1);
    DashboardAnalytics::forgetCompany($company->id);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $payload = app(DashboardAnalytics::class)->primaryForCompany($company->id);
    $todayAggregates = collect(DB::getQueryLog())->filter(
        fn (array $query): bool => str_contains(strtolower($query['query']), 'attendance_records')
            && str_contains(strtolower($query['query']), 'check_ins_today'),
    );

    expect($todayAggregates)->toHaveCount(1)
        ->and($payload['attendance_analytics']['events_today'])->toBeGreaterThan(0)
        ->and($payload['attendance_analytics']['check_ins_today'])->toBeInt()
        ->and($payload['attendance_analytics']['present_today'])->toBeInt();

    Carbon::setTestNow();
});

test('dashboard analytics cache keys are company specific', function () {
    DashboardAnalytics::$forceCacheInTests = true;

    ['company' => $companyA] = seedDashboardAnalyticsFixtures(4, 2);
    ['company' => $companyB] = seedDashboardAnalyticsFixtures(8, 2);

    DashboardAnalytics::forgetCompany($companyA->id);
    DashboardAnalytics::forgetCompany($companyB->id);

    $payloadA = app(DashboardAnalytics::class)->primaryForCompany($companyA->id);
    $payloadB = app(DashboardAnalytics::class)->primaryForCompany($companyB->id);

    expect(Cache::has(DashboardAnalytics::cacheKey($companyA->id)))->toBeTrue()
        ->and(Cache::has(DashboardAnalytics::cacheKey($companyB->id)))->toBeTrue()
        ->and($payloadA['employee_analytics']['total'])->not->toBe($payloadB['employee_analytics']['total'])
        ->and(Cache::get(DashboardAnalytics::cacheKey($companyA->id))['employee_analytics']['total'])
        ->toBe($payloadA['employee_analytics']['total'])
        ->and(Cache::get(DashboardAnalytics::cacheKey($companyB->id))['employee_analytics']['total'])
        ->toBe($payloadB['employee_analytics']['total']);

    DashboardAnalytics::$forceCacheInTests = false;
    Carbon::setTestNow();
});

test('dashboard analytics exclude cross company records', function () {
    ['company' => $companyA] = seedDashboardAnalyticsFixtures(3, 2);
    ['company' => $companyB, 'employee' => $otherEmployee] = makeDocumentFixtures();

    AttendanceRecord::query()->create([
        'company_id' => $companyB->id,
        'employee_id' => $otherEmployee->id,
        'date' => '2026-06-15',
        'clock_in' => '2026-06-15 08:00:00',
        'status' => AttendanceRecord::STATUS_PRESENT,
        'source' => AttendanceRecord::SOURCE_MANUAL,
    ]);

    DashboardAnalytics::forgetCompany($companyA->id);
    $payload = app(DashboardAnalytics::class)->forCompany($companyA->id);

    expect($payload['attendance_analytics']['check_ins_today'])->toBe(
        AttendanceRecord::query()
            ->where('company_id', $companyA->id)
            ->whereDate('date', '2026-06-15')
            ->whereNotNull('clock_in')
            ->count()
    )->and($payload['organization_snapshot']['branches'])->toBe(
        Branch::query()->where('company_id', $companyA->id)->count()
    );

    Carbon::setTestNow();
});

test('dashboard weekly trends fill missing days with zeros', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Asia/Dubai'));
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    $company->update(['timezone' => 'Asia/Dubai']);

    AttendanceRecord::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'date' => '2026-06-15',
        'clock_in' => '2026-06-15 09:00:00',
        'clock_out' => '2026-06-15 17:00:00',
        'status' => AttendanceRecord::STATUS_PRESENT,
        'source' => AttendanceRecord::SOURCE_MANUAL,
    ]);

    DashboardAnalytics::forgetCompany($company->id);
    $trends = app(DashboardAnalytics::class)->forCompany($company->id)['attendance_analytics']['weekly_trends'];

    expect($trends)->toHaveCount(7)
        ->and($trends[6]['check_ins'])->toBe(1)
        ->and($trends[6]['check_outs'])->toBe(1)
        ->and($trends[0]['check_ins'])->toBe(0)
        ->and($trends[0]['check_outs'])->toBe(0);

    Carbon::setTestNow();
});
