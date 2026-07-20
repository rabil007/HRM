<?php

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\Dashboard\DashboardAnalytics;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

it('excludes soft-deleted employees and documents from workforce trend aggregates', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Asia/Dubai'));
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $activeHire = Employee::factory()->forCompany($company)->create([
        'created_at' => now()->subMonths(1),
    ]);
    $deletedHire = Employee::factory()->forCompany($company)->create([
        'created_at' => now()->subMonths(1),
    ]);
    $deletedHire->delete();

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'passport',
        'title' => 'Active passport',
        'file_path' => 'documents/active-passport.pdf',
        'expiry_date' => now()->addYear()->toDateString(),
        'status' => 'valid',
        'created_at' => now()->subMonths(1),
    ]);

    $deletedDocument = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'visa',
        'title' => 'Deleted visa',
        'file_path' => 'documents/deleted-visa.pdf',
        'expiry_date' => now()->addYear()->toDateString(),
        'status' => 'valid',
        'created_at' => now()->subMonths(1),
    ]);
    $deletedDocument->delete();

    $other = makeDocumentFixtures();
    Employee::factory()->forCompany($other['company'])->create([
        'created_at' => now()->subMonths(1),
    ]);
    EmployeeDocument::query()->create([
        'company_id' => $other['company']->id,
        'employee_id' => $other['employee']->id,
        'document_type_id' => $other['passportType']->id,
        'type' => 'passport',
        'title' => 'Other company passport',
        'file_path' => 'documents/other-passport.pdf',
        'expiry_date' => now()->addYear()->toDateString(),
        'status' => 'valid',
        'created_at' => now()->subMonths(1),
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $trends = app(DashboardAnalytics::class)->workforceTrends($company->id);
    $queries = collect(DB::getQueryLog())->pluck('query')->map(fn (string $query): string => strtolower($query));

    $may = collect($trends)->firstWhere('month', now()->subMonths(1)->format('M'));

    expect($may)->not->toBeNull()
        ->and($may['new_hires'])->toBe(1)
        ->and($may['documents'])->toBe(1)
        ->and($queries->filter(fn (string $query): bool => str_contains($query, 'from "employees"') || str_contains($query, 'from `employees`'))->count())->toBeLessThanOrEqual(3)
        ->and($queries->contains(fn (string $query): bool => str_contains($query, 'deleted_at')))->toBeTrue();

    expect($activeHire->id)->toBeInt();

    Carbon::setTestNow();
});
