<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Support\Dashboard\DashboardAnalytics;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

afterEach(function () {
    DashboardAnalytics::$forceCacheInTests = false;
});

test('dashboard scopes metrics and prevents cache pollution between unrestricted and restricted users', function () {
    DashboardAnalytics::$forceCacheInTests = true;

    ['user' => $unrestrictedUser, 'company' => $company, 'marineDept' => $marineDept, 'officeDept' => $officeDept, 'marineEmployee' => $marineEmployee, 'officeEmployee' => $officeEmployee] = makeEmployeeVisibilityFixtures();

    // Give unrestricted user all dashboard permissions
    grantCompanyPermissions($unrestrictedUser, $company, [
        'employees.view',
        'documents.view',
        'attendance.overview.view',
        'contracts.view',
        'training.view',
        'bank_accounts.view',
        'audit.view',
    ], 'admin-role');

    // Create restricted user restricted to Marine department
    $restrictedUser = User::factory()->create();
    grantCompanyPermissions($restrictedUser, $company, [
        'employees.view',
        'documents.view',
        'attendance.overview.view',
        'contracts.view',
        'training.view',
        'bank_accounts.view',
        'audit.view',
    ]);
    restrictUserToDepartments($restrictedUser, $company, [$marineDept->id]);

    // Create activities
    Activity::query()->create([
        'company_id' => $company->id,
        'log_name' => 'default',
        'description' => 'Updated Marine Crew',
        'subject_type' => Employee::class,
        'subject_id' => $marineEmployee->id,
        'causer_type' => User::class,
        'causer_id' => $unrestrictedUser->id,
        'properties' => ['attributes' => ['name' => 'Marine Crew']],
    ]);

    Activity::query()->create([
        'company_id' => $company->id,
        'log_name' => 'default',
        'description' => 'Updated Office Staff',
        'subject_type' => Employee::class,
        'subject_id' => $officeEmployee->id,
        'causer_type' => User::class,
        'causer_id' => $unrestrictedUser->id,
        'properties' => ['attributes' => ['name' => 'Office Staff']],
    ]);

    // 1. Unrestricted user visits dashboard first -> warms cache with company-wide totals (2 employees)
    $responseUnrestricted = $this->actingAs($unrestrictedUser)
        ->get(route('dashboard'))
        ->assertOk();

    $responseUnrestricted->assertInertia(function (Assert $page) {
        $page->component('dashboard')
            ->where('employee_analytics.total', 2)
            ->where('employee_analytics.active', 2)
            ->has('audit_summary.recent');

        $recentDescriptions = collect($page->toArray()['props']['audit_summary']['recent'])
            ->pluck('description')
            ->all();

        expect($recentDescriptions)
            ->toContain('Updated Marine Crew')
            ->toContain('Updated Office Staff');
    });

    // 2. Restricted user visits dashboard -> MUST NOT see cached company totals (should see 1 Marine employee)
    $responseRestricted = $this->actingAs($restrictedUser)
        ->get(route('dashboard'))
        ->assertOk();

    $responseRestricted->assertInertia(function (Assert $page) {
        $page->component('dashboard')
            ->where('employee_analytics.total', 1)
            ->has('audit_summary.recent');

        $recentDescriptions = collect($page->toArray()['props']['audit_summary']['recent'])
            ->pluck('description')
            ->all();

        expect($recentDescriptions)
            ->toContain('Updated Marine Crew')
            ->not->toContain('Updated Office Staff');
    });

    // 3. Unrestricted user visits again -> MUST still see full company totals (2 employees), not restricted cache
    $responseUnrestrictedAgain = $this->actingAs($unrestrictedUser)
        ->get(route('dashboard'))
        ->assertOk();

    $responseUnrestrictedAgain->assertInertia(function (Assert $page) {
        $page->component('dashboard')
            ->where('employee_analytics.total', 2)
            ->where('employee_analytics.active', 2);
    });
});
