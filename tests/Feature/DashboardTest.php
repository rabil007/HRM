<?php

use App\Models\Employee;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard returns employee analytics and document compliance props', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeDocumentFixtures();

    Employee::factory()->count(3)->create([
        'company_id' => $company->id,
        'status' => 'active',
    ]);

    Employee::factory()->create([
        'company_id' => $company->id,
        'status' => 'on_leave',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->has('employee_analytics')
            ->has('employee_analytics.total')
            ->has('employee_analytics.active')
            ->has('employee_analytics.on_leave')
            ->has('employee_analytics.inactive')
            ->has('employee_analytics.terminated')
            ->has('employee_analytics.new_hires_this_month')
            ->has('document_compliance')
            ->has('document_compliance.total_documents')
            ->has('document_compliance.expired')
            ->has('document_compliance.expiring_30')
            ->has('document_compliance.expiring_15')
            ->has('document_compliance.expiring_7')
            ->has('document_compliance.uploaded_this_month')
            ->has('document_compliance.compliance_rate')
            ->has('workforce_trends')
            ->has('employees_by_department')
            ->has('employees_by_branch')
            ->has('document_health')
            ->has('organization_snapshot')
            ->has('recent_hires')
        );
});
