<?php

use App\Models\Branch;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

test('restricted role in activity logs only sees allowed department employee activities and organization activities', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'marineEmployee' => $marineEmployee, 'officeEmployee' => $officeEmployee] = makeEmployeeVisibilityFixtures();

    grantCompanyPermissions($user, $company, ['audit.view']);
    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $branch = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'HQ Port',
        'code' => 'HQP',
        'city' => 'Dubai',
        'country' => 'UAE',
        'status' => 'active',
    ]);

    $docType = DocumentType::query()->firstOrCreate(
        ['title' => 'Passport'],
        ['is_active' => true],
    );

    $marineDoc = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $marineEmployee->id,
        'document_type_id' => $docType->id,
        'type' => 'identification',
        'file_path' => 'docs/marine.pdf',
        'original_filename' => 'marine.pdf',
        'title' => 'Marine Passport',
        'size_bytes' => 1024,
    ]);

    $officeDoc = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $officeEmployee->id,
        'document_type_id' => $docType->id,
        'type' => 'identification',
        'file_path' => 'docs/office.pdf',
        'original_filename' => 'office.pdf',
        'title' => 'Office Passport',
        'size_bytes' => 1024,
    ]);

    $actor = User::factory()->create();

    $marineEmpActivity = Activity::query()->create([
        'company_id' => $company->id,
        'log_name' => 'default',
        'description' => 'Updated Marine Crew profile',
        'subject_type' => Employee::class,
        'subject_id' => $marineEmployee->id,
        'causer_type' => User::class,
        'causer_id' => $actor->id,
        'properties' => ['attributes' => ['name' => 'Marine Crew']],
    ]);

    $marineDocActivity = Activity::query()->create([
        'company_id' => $company->id,
        'log_name' => 'default',
        'description' => 'Uploaded Marine Passport',
        'subject_type' => EmployeeDocument::class,
        'subject_id' => $marineDoc->id,
        'causer_type' => User::class,
        'causer_id' => $actor->id,
        'properties' => ['attributes' => ['title' => 'Marine Passport']],
    ]);

    $officeEmpActivity = Activity::query()->create([
        'company_id' => $company->id,
        'log_name' => 'default',
        'description' => 'Updated Office Staff profile',
        'subject_type' => Employee::class,
        'subject_id' => $officeEmployee->id,
        'causer_type' => User::class,
        'causer_id' => $actor->id,
        'properties' => ['attributes' => ['name' => 'Office Staff']],
    ]);

    $officeDocActivity = Activity::query()->create([
        'company_id' => $company->id,
        'log_name' => 'default',
        'description' => 'Uploaded Office Passport',
        'subject_type' => EmployeeDocument::class,
        'subject_id' => $officeDoc->id,
        'causer_type' => User::class,
        'causer_id' => $actor->id,
        'properties' => ['attributes' => ['title' => 'Office Passport']],
    ]);

    $branchActivity = Activity::query()->create([
        'company_id' => $company->id,
        'log_name' => 'default',
        'description' => 'Updated HQ Port branch',
        'subject_type' => Branch::class,
        'subject_id' => $branch->id,
        'causer_type' => User::class,
        'causer_id' => $actor->id,
        'properties' => ['attributes' => ['name' => 'HQ Port']],
    ]);

    $response = $this->actingAs($user)
        ->get('/organization/activity-logs')
        ->assertOk();

    $response->assertInertia(function (Assert $page) use (
        $marineEmpActivity,
        $marineDocActivity,
        $branchActivity,
        $officeEmpActivity,
        $officeDocActivity
    ) {
        $page->component('organization/activity-logs')
            ->has('logs', 3)
            ->where('summary.total', 3);

        $logIds = collect($page->toArray()['props']['logs'])->pluck('id')->all();
        expect($logIds)->toContain($marineEmpActivity->id)
            ->toContain($marineDocActivity->id)
            ->toContain($branchActivity->id)
            ->not->toContain($officeEmpActivity->id)
            ->not->toContain($officeDocActivity->id);
    });
});

test('restricted role cannot find hidden department employee logs by search', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'officeEmployee' => $officeEmployee] = makeEmployeeVisibilityFixtures();

    grantCompanyPermissions($user, $company, ['audit.view']);
    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $actor = User::factory()->create();

    Activity::query()->create([
        'company_id' => $company->id,
        'log_name' => 'default',
        'description' => 'Updated Office Staff profile',
        'subject_type' => Employee::class,
        'subject_id' => $officeEmployee->id,
        'causer_type' => User::class,
        'causer_id' => $actor->id,
        'properties' => ['attributes' => ['name' => 'Office Staff']],
    ]);

    $response = $this->actingAs($user)
        ->get('/organization/activity-logs?q=Staff')
        ->assertOk();

    $response->assertInertia(function (Assert $page) {
        $page->component('organization/activity-logs')
            ->has('logs', 0)
            ->where('summary.total', 0);
    });
});

test('unrestricted role sees all activity logs', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'marineEmployee' => $marineEmployee, 'officeEmployee' => $officeEmployee] = makeEmployeeVisibilityFixtures();

    grantCompanyPermissions($user, $company, ['audit.view']);

    $actor = User::factory()->create();

    Activity::query()->create([
        'company_id' => $company->id,
        'log_name' => 'default',
        'description' => 'Updated Marine Crew profile',
        'subject_type' => Employee::class,
        'subject_id' => $marineEmployee->id,
        'causer_type' => User::class,
        'causer_id' => $actor->id,
        'properties' => ['attributes' => ['name' => 'Marine Crew']],
    ]);

    Activity::query()->create([
        'company_id' => $company->id,
        'log_name' => 'default',
        'description' => 'Updated Office Staff profile',
        'subject_type' => Employee::class,
        'subject_id' => $officeEmployee->id,
        'causer_type' => User::class,
        'causer_id' => $actor->id,
        'properties' => ['attributes' => ['name' => 'Office Staff']],
    ]);

    $response = $this->actingAs($user)
        ->get('/organization/activity-logs')
        ->assertOk();

    $response->assertInertia(function (Assert $page) {
        $page->component('organization/activity-logs')
            ->has('logs', 2)
            ->where('summary.total', 2);
    });
});
