<?php

use App\Models\Client;
use App\Models\CompanyVisaType;
use App\Models\Rank;
use App\Support\CrewMovements\CurrentCrewQuery;
use Illuminate\Support\Facades\Schema;

test('crew master data tables do not use company_id columns', function () {
    expect(Schema::hasColumn('ranks', 'company_id'))->toBeFalse()
        ->and(Schema::hasColumn('vessels', 'company_id'))->toBeFalse()
        ->and(Schema::hasColumn('clients', 'company_id'))->toBeFalse()
        ->and(Schema::hasColumn('company_visa_types', 'company_id'))->toBeFalse()
        ->and(Schema::hasColumn('employees', 'employee_no'))->toBeTrue()
        ->and(Schema::hasColumn('employees', 'employee_number'))->toBeFalse();
});

test('current crew filter options load global master data without company scoping', function () {
    ['company' => $company] = makeCrewAssignmentFixtures();

    Rank::query()->create(['name' => 'Schema Rank '.uniqid(), 'is_active' => true]);
    makeCrewMovementVessel('Schema Vessel');
    Client::query()->create(['name' => 'Schema Client '.uniqid(), 'is_active' => true]);
    CompanyVisaType::query()->create(['name' => 'Schema Visa '.uniqid(), 'is_active' => true]);

    $options = CurrentCrewQuery::filterOptions($company->id);

    expect($options['ranks'])->not->toBeEmpty()
        ->and($options['vessels'])->not->toBeEmpty()
        ->and($options['clients'])->not->toBeEmpty()
        ->and($options['employees'])->not->toBeEmpty()
        ->and($options['employees'][0])->toHaveKeys(['id', 'name', 'employee_no']);
});

test('create assignment page loads without querying nonexistent master company_id columns', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    CompanyVisaType::query()->create([
        'name' => 'Create Page Visa '.uniqid(),
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('organization/crew/create')
            ->has('form_options.visa_types')
            ->has('form_options.clients')
            ->has('form_options.ranks')
            ->has('form_options.vessels')
            ->has('form_options.courses')
            ->has('form_options.employees'));
});
