<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\CrewAssignment;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselManning;
use App\Models\VesselType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function makeOrganizationVesselFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'OVS',
        'name' => 'Org Vessel Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'OVS',
        'name' => 'Org Vessel Currency',
        'symbol' => 'V$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Org Vessel Co',
        'slug' => 'org-vessel-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $otherCompany = Company::query()->create([
        'name' => 'Other Org Vessel Co',
        'slug' => 'other-org-vessel-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $vesselType = VesselType::query()->create([
        'name' => 'AHTS',
        'is_active' => true,
    ]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.vessels.view',
        'crew_operations.vessels.create',
        'crew_operations.vessels.update',
        'crew_operations.vessels.delete',
        'crew_operations.vessel_manning.view',
    ]);

    return compact('user', 'company', 'otherCompany', 'vesselType');
}

test('guests cannot access organization vessels page', function () {
    $this->get(route('organization.vessels.index'))->assertRedirect(route('login'));
});

test('authorized users can view create update and delete company vessels', function () {
    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType] = makeOrganizationVesselFixtures();

    $this->actingAs($user)
        ->get(route('organization.vessels.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/vessels/index', false)
            ->has('vessels')
            ->where('can.create', true)
        );

    $this->actingAs($user)
        ->post(route('organization.vessels.store'), [
            'name' => 'ADNOC 951',
            'vessel_type_id' => $vesselType->id,
            'grt' => 4500,
            'bhp' => 12000,
            'is_active' => true,
        ])
        ->assertRedirect(route('organization.vessels.index'));

    $vessel = Vessel::query()->where('name', 'ADNOC 951')->first();
    expect($vessel)->not->toBeNull()
        ->and((int) $vessel->company_id)->toBe((int) $company->id);

    $this->actingAs($user)
        ->put(route('organization.vessels.update', $vessel), [
            'name' => 'ADNOC 951 Updated',
            'vessel_type_id' => $vesselType->id,
            'grt' => 4600,
            'bhp' => 12500,
            'is_active' => true,
        ])
        ->assertRedirect(route('organization.vessels.index'));

    $this->assertDatabaseHas('vessels', [
        'id' => $vessel->id,
        'company_id' => $company->id,
        'name' => 'ADNOC 951 Updated',
        'grt' => '4600.00',
        'bhp' => 12500,
    ]);

    $this->actingAs($user)
        ->delete(route('organization.vessels.destroy', $vessel))
        ->assertRedirect(route('organization.vessels.index'));

    $this->assertSoftDeleted('vessels', ['id' => $vessel->id]);
});

test('created vessels always use current_company_id and ignore client company_id', function () {
    ['user' => $user, 'company' => $company, 'otherCompany' => $otherCompany, 'vesselType' => $vesselType] = makeOrganizationVesselFixtures();

    $this->actingAs($user)
        ->post(route('organization.vessels.store'), [
            'name' => 'Scoped Vessel',
            'company_id' => $otherCompany->id,
            'vessel_type_id' => $vesselType->id,
            'is_active' => true,
        ])
        ->assertRedirect(route('organization.vessels.index'));

    $this->assertDatabaseHas('vessels', [
        'name' => 'Scoped Vessel',
        'company_id' => $company->id,
    ]);

    $this->assertDatabaseMissing('vessels', [
        'name' => 'Scoped Vessel',
        'company_id' => $otherCompany->id,
    ]);
});

test('vessels index and show are isolated by company', function () {
    ['user' => $user, 'company' => $company, 'otherCompany' => $otherCompany, 'vesselType' => $vesselType] = makeOrganizationVesselFixtures();

    $ownVessel = Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'Own Vessel',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $foreignVessel = Vessel::query()->create([
        'company_id' => $otherCompany->id,
        'name' => 'Foreign Vessel',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('organization.vessels.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/vessels/index', false)
            ->has('vessels', 1)
            ->where('vessels.0.id', $ownVessel->id)
            ->where('vessels.0.name', 'Own Vessel')
        );

    $this->actingAs($user)
        ->get(route('organization.vessels.show', $ownVessel))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/vessels/show', false)
            ->where('vessel.id', $ownVessel->id)
            ->has('summary')
        );

    $this->actingAs($user)
        ->get(route('organization.vessels.show', $foreignVessel))
        ->assertNotFound();

    $this->actingAs($user)
        ->put(route('organization.vessels.update', $foreignVessel), [
            'name' => 'Hacked',
            'vessel_type_id' => $vesselType->id,
            'is_active' => true,
        ])
        ->assertNotFound();

    $this->actingAs($user)
        ->delete(route('organization.vessels.destroy', $foreignVessel))
        ->assertNotFound();
});

test('vessel names are unique per company but can repeat across companies', function () {
    ['user' => $user, 'company' => $company, 'otherCompany' => $otherCompany, 'vesselType' => $vesselType] = makeOrganizationVesselFixtures();

    Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'Shared Name',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    Vessel::query()->create([
        'company_id' => $otherCompany->id,
        'name' => 'Shared Name',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('organization.vessels.store'), [
            'name' => 'Shared Name',
            'vessel_type_id' => $vesselType->id,
            'is_active' => true,
        ])
        ->assertSessionHasErrors('name');

    expect(Vessel::query()->where('company_id', $company->id)->where('name', 'Shared Name')->count())->toBe(1)
        ->and(Vessel::query()->where('name', 'Shared Name')->count())->toBe(2);
});

test('authorized users can download template and import vessels scoped to company', function () {
    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType] = makeOrganizationVesselFixtures();

    VesselType::query()->whereKey($vesselType->id)->update(['name' => 'H/LIFT']);

    $this->actingAs($user)
        ->get(route('organization.vessels.import.template'))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $csv = "name,vessel_type,grt,bhp,is_active\nSAPURA 1200,H/LIFT,5000,9000,yes\n";
    $file = UploadedFile::fake()->createWithContent('vessels.csv', $csv);

    $this->actingAs($user)
        ->post(route('organization.vessels.import'), [
            'file' => $file,
        ])
        ->assertRedirect(route('organization.vessels.index'));

    $this->assertDatabaseHas('vessels', [
        'company_id' => $company->id,
        'name' => 'SAPURA 1200',
        'grt' => '5000.00',
        'bhp' => 9000,
    ]);
});

test('deleting a vessel is blocked when referenced by sea service or crew assignment', function () {
    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType] = makeOrganizationVesselFixtures();

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $rank = Rank::query()->create(['name' => 'Master', 'is_active' => true]);

    $seaServiceVessel = Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'Sea Service Vessel',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    EmployeeSeaService::factory()->forEmployee($employee)->create([
        'vessel_type_id' => $vesselType->id,
        'vessel_id' => $seaServiceVessel->id,
    ]);

    $this->actingAs($user)
        ->from(route('organization.vessels.index'))
        ->delete(route('organization.vessels.destroy', $seaServiceVessel))
        ->assertRedirect(route('organization.vessels.index'))
        ->assertSessionHasErrors('name');

    expect(Vessel::query()->find($seaServiceVessel->id))->not->toBeNull();

    $assignmentVessel = Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'Assignment Vessel',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    makeActiveOnVesselAssignment($company, $employee, $rank, $assignmentVessel);

    $this->actingAs($user)
        ->from(route('organization.vessels.index'))
        ->delete(route('organization.vessels.destroy', $assignmentVessel))
        ->assertRedirect(route('organization.vessels.index'))
        ->assertSessionHasErrors('name');

    expect(Vessel::query()->find($assignmentVessel->id))->not->toBeNull();
    expect(CrewAssignment::query()->where('vessel_id', $assignmentVessel->id)->exists())->toBeTrue();
});

test('authorized users can store vessel identification and certificate', function () {
    Storage::fake('public');

    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType] = makeOrganizationVesselFixtures();

    $certificate = UploadedFile::fake()->create('vessel-cert.pdf', 120, 'application/pdf');

    $this->actingAs($user)
        ->post(route('organization.vessels.store'), [
            'name' => 'MV Certificate',
            'vessel_type_id' => $vesselType->id,
            'grt' => 3200,
            'bhp' => 8000,
            'official_no' => 'OFF-1001',
            'call_sign' => 'A6XYZ',
            'imo_no' => '9123456',
            'certificate' => $certificate,
            'is_active' => true,
        ])
        ->assertRedirect(route('organization.vessels.index'));

    $vessel = Vessel::query()->where('name', 'MV Certificate')->first();
    expect($vessel)->not->toBeNull()
        ->and((int) $vessel->company_id)->toBe((int) $company->id)
        ->and($vessel->official_no)->toBe('OFF-1001')
        ->and($vessel->call_sign)->toBe('A6XYZ')
        ->and($vessel->imo_no)->toBe('9123456')
        ->and($vessel->certificate_path)->not->toBeNull()
        ->and($vessel->certificate_original_filename)->toBe('vessel-cert.pdf');

    Storage::disk('public')->assertExists($vessel->certificate_path);

    $this->actingAs($user)
        ->get(route('organization.vessels.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/vessels/index', false)
            ->has('vessels', 1)
            ->where('vessels.0.official_no', 'OFF-1001')
            ->where('vessels.0.call_sign', 'A6XYZ')
            ->where('vessels.0.imo_no', '9123456')
            ->where('vessels.0.certificate_original_filename', 'vessel-cert.pdf')
            ->whereNot('vessels.0.certificate_url', null)
        );

    $previousPath = $vessel->certificate_path;
    $replacement = UploadedFile::fake()->create('vessel-cert-v2.pdf', 140, 'application/pdf');

    $this->actingAs($user)
        ->put(route('organization.vessels.update', $vessel), [
            'name' => 'MV Certificate',
            'vessel_type_id' => $vesselType->id,
            'grt' => 3200,
            'bhp' => 8000,
            'official_no' => 'OFF-2002',
            'call_sign' => 'A6ABC',
            'imo_no' => '9654321',
            'certificate' => $replacement,
            'is_active' => true,
        ])
        ->assertRedirect(route('organization.vessels.index'));

    $vessel->refresh();
    expect($vessel->official_no)->toBe('OFF-2002')
        ->and($vessel->call_sign)->toBe('A6ABC')
        ->and($vessel->imo_no)->toBe('9654321')
        ->and($vessel->certificate_original_filename)->toBe('vessel-cert-v2.pdf')
        ->and($vessel->certificate_path)->not->toBe($previousPath);

    Storage::disk('public')->assertExists($vessel->certificate_path);
    Storage::disk('public')->assertMissing($previousPath);
});

test('settings master-data vessels routes redirect to organization vessels', function () {
    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType] = makeOrganizationVesselFixtures();

    grantCompanyPermissions($user, $company, [
        'settings.master-data.vessels.view',
        'settings.master-data.vessels.create',
        'settings.master-data.vessels.update',
        'settings.master-data.vessels.delete',
    ]);

    $vessel = Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'Redirect Vessel',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get('/settings/master-data/vessels')
        ->assertRedirect(route('organization.vessels.index'));

    $this->actingAs($user)
        ->get("/settings/master-data/vessels/{$vessel->id}")
        ->assertRedirect(route('organization.vessels.show', $vessel));

    $this->actingAs($user)
        ->get('/settings/master-data/vessels/import/template')
        ->assertRedirect(route('organization.vessels.import.template'));
});

test('users without vessels view permission cannot access organization vessels', function () {
    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType] = makeOrganizationVesselFixtures();

    $vessel = Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'Forbidden Vessel',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    grantCompanyPermissions($user, $company, []);

    $this->actingAs($user)
        ->get(route('organization.vessels.index'))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('organization.vessels.show', $vessel))
        ->assertForbidden();
});

test('vessel manning viewers cannot open the vessels index', function () {
    ['user' => $user, 'company' => $company] = makeOrganizationVesselFixtures();

    grantCompanyPermissions($user, $company, ['crew_operations.vessel_manning.view']);

    $this->actingAs($user)
        ->get(route('organization.vessels.index'))
        ->assertForbidden();
});

test('vessel show includes manning ranks and manning permissions', function () {
    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType] = makeOrganizationVesselFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.vessels.view',
        'crew_operations.vessel_manning.view',
        'crew_operations.vessel_manning.create',
        'crew_operations.vessel_manning.update',
        'crew_operations.vessel_manning.delete',
    ]);

    $vessel = Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'Manning Show Vessel',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $captain = Rank::query()->create(['name' => 'Captain', 'is_active' => true]);
    $welder = Rank::query()->create(['name' => 'Welder', 'is_active' => true]);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'required_count' => 1,
    ]);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $welder->id,
        'required_count' => 2,
    ]);

    $this->actingAs($user)
        ->get(route('organization.vessels.show', $vessel))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/vessels/show', false)
            ->where('vessel.total_required', 3)
            ->where('vessel.ranks_configured', 2)
            ->has('vessel.manning', 2)
            ->has('ranks', 2)
            ->where('manning_can.create', true)
            ->where('manning_can.update', true)
            ->where('manning_can.delete', true)
            ->where('summary.manning_ranks', 2)
            ->where('summary.total_required', 3)
            ->where('can_view_audit', false)
            ->where('recent_activity', [])
        );
});

test('vessel show exposes recent activity when audit view is granted', function () {
    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType] = makeOrganizationVesselFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.vessels.view',
        'audit.view',
    ]);

    $vessel = Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'Audit Vessel',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('organization.vessels.show', $vessel))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/vessels/show', false)
            ->where('can_view_audit', true)
            ->has('recent_activity', 1)
            ->where('recent_activity.0.event', 'created')
        );
});

test('vessel manning get routes redirect to organization vessels', function () {
    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType] = makeOrganizationVesselFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.vessels.view',
        'crew_operations.vessel_manning.view',
    ]);

    $vessel = Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'Redirect Manning Vessel',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('organization.vessel-manning.index', ['search' => 'Redirect']))
        ->assertRedirect(route('organization.vessels.index', ['search' => 'Redirect']));

    $this->actingAs($user)
        ->get(route('organization.vessel-manning.show', [
            'vessel' => $vessel,
            'search' => 'Redirect',
        ]))
        ->assertRedirect(route('organization.vessels.show', [
            'vessel' => $vessel,
            'search' => 'Redirect',
        ]));
});

test('vessels index includes manning summary totals', function () {
    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType] = makeOrganizationVesselFixtures();

    $vessel = Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'Summary Vessel',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $captain = Rank::query()->create(['name' => 'Master', 'is_active' => true]);
    $engineer = Rank::query()->create(['name' => 'Chief Engineer', 'is_active' => true]);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'required_count' => 1,
    ]);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $engineer->id,
        'required_count' => 3,
    ]);

    $this->actingAs($user)
        ->get(route('organization.vessels.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/vessels/index', false)
            ->has('vessels', 1)
            ->where('vessels.0.name', 'Summary Vessel')
            ->where('vessels.0.ranks_configured', 2)
            ->where('vessels.0.total_required', 4)
            ->has('vessels.0.manning', 2)
        );
});

test('same vessel name can exist in different companies', function () {
    ['user' => $user, 'company' => $company, 'otherCompany' => $otherCompany, 'vesselType' => $vesselType] = makeOrganizationVesselFixtures();

    $vesselA = Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'Ocean Star',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $vesselB = Vessel::query()->create([
        'company_id' => $otherCompany->id,
        'name' => 'Ocean Star',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    expect($vesselA->id)->not->toBe($vesselB->id)
        ->and($vesselA->name)->toBe('Ocean Star')
        ->and($vesselB->name)->toBe('Ocean Star')
        ->and((int) $vesselA->company_id)->toBe((int) $company->id)
        ->and((int) $vesselB->company_id)->toBe((int) $otherCompany->id);
});

test('vessel type remains global and accessible across different companies', function () {
    ['company' => $company, 'otherCompany' => $otherCompany, 'vesselType' => $vesselType] = makeOrganizationVesselFixtures();

    $vesselA = Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'Vessel A',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $vesselB = Vessel::query()->create([
        'company_id' => $otherCompany->id,
        'name' => 'Vessel B',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    expect($vesselA->vessel_type_id)->toBe($vesselType->id)
        ->and($vesselB->vessel_type_id)->toBe($vesselType->id)
        ->and(Schema::hasColumn('vessel_types', 'company_id'))->toBeFalse();
});
