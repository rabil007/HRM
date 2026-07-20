<?php

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\Dashboard\DashboardAnalytics;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

it('aggregates workforce trends in sql without loading every timestamp row', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Asia/Dubai'));
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    Employee::factory()->count(10)->forCompany($company)->create([
        'created_at' => now()->subMonths(2),
    ]);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'passport',
        'title' => 'Passport',
        'file_path' => 'documents/dashboard-aggregate.pdf',
        'expiry_date' => now()->addYear()->toDateString(),
        'status' => 'valid',
        'created_at' => now()->subMonths(2),
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $trends = app(DashboardAnalytics::class)->workforceTrends($company->id);
    $queries = collect(DB::getQueryLog())->pluck('query')->map(fn (string $query): string => strtolower($query));

    expect($trends)->toHaveCount(6)
        ->and($queries->filter(fn (string $query): bool => str_contains($query, 'group by') && str_contains($query, 'created_at')))->toHaveCount(2)
        ->and($queries->contains(fn (string $query): bool => str_contains($query, 'select "created_at" from "employees"')))->toBeFalse()
        ->and($queries->contains(fn (string $query): bool => str_contains($query, 'select "created_at" from "employee_documents"')))->toBeFalse();

    Carbon::setTestNow();
});

it('uses index friendly date predicates and preserves date keys in negative timezones', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'America/New_York'));
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    $company->update(['timezone' => 'America/New_York']);

    AttendanceRecord::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'date' => '2026-06-15',
        'clock_in' => '2026-06-15 09:00:00',
        'clock_out' => '2026-06-15 17:00:00',
        'status' => AttendanceRecord::STATUS_PRESENT,
        'source' => AttendanceRecord::SOURCE_MANUAL,
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $attendance = app(DashboardAnalytics::class)->primaryForCompany($company->id)['attendance_analytics'];
    $queries = collect(DB::getQueryLog())
        ->pluck('query')
        ->map(fn (string $query): string => strtolower($query))
        ->filter(fn (string $query): bool => str_contains($query, 'attendance_records'));

    expect($attendance['check_ins_today'])->toBe(1)
        ->and($attendance['weekly_trends'][6]['check_ins'])->toBe(1)
        ->and($queries->contains(fn (string $query): bool => str_contains($query, 'date(')))->toBeFalse();

    Carbon::setTestNow();
});
