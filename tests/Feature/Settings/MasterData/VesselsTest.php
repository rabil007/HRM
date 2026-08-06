<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot access vessels page', function () {
    $this->get('/settings/master-data/vessels')->assertRedirect(route('login'));
});

test('authorized users can view, create, update, and delete vessels', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'VSS',
        'name' => 'Vessel Ship Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'VSS',
        'name' => 'Vessel Ship Currency',
        'symbol' => 'S$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Vessel Ship Co',
        'slug' => 'vessel-ship-co',
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
        'settings.master-data.vessels.view',
        'settings.master-data.vessels.create',
        'settings.master-data.vessels.update',
        'settings.master-data.vessels.delete',
    ]);

    $this->get('/settings/master-data/vessels')->assertOk();

    $this->post('/settings/master-data/vessels', [
        'name' => 'ADNOC 951',
        'vessel_type_id' => $vesselType->id,
        'grt' => 4500,
        'bhp' => 12000,
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.vessels.index'));

    $id = Vessel::query()->where('name', 'ADNOC 951')->value('id');
    expect($id)->not->toBeNull();

    $this->put("/settings/master-data/vessels/{$id}", [
        'name' => 'ADNOC 951 Updated',
        'vessel_type_id' => $vesselType->id,
        'grt' => 4600,
        'bhp' => 12500,
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.vessels.index'));

    $this->assertDatabaseHas('vessels', [
        'id' => $id,
        'name' => 'ADNOC 951 Updated',
        'grt' => '4600.00',
        'bhp' => 12500,
    ]);

    $this->delete("/settings/master-data/vessels/{$id}")
        ->assertRedirect(route('settings.master-data.vessels.index'));

    $this->assertSoftDeleted('vessels', ['id' => $id]);
});

test('authorized users can download template and import vessels from csv', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'VIM',
        'name' => 'Vessel Import Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'VIM',
        'name' => 'Vessel Import Currency',
        'symbol' => 'I$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Vessel Import Co',
        'slug' => 'vessel-import-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    VesselType::query()->create([
        'name' => 'H/LIFT',
        'is_active' => true,
    ]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.vessels.view',
        'settings.master-data.vessels.create',
    ]);

    $this->get('/settings/master-data/vessels/import/template')
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $csv = "name,vessel_type,grt,bhp,is_active\nSAPURA 1200,H/LIFT,5000,9000,yes\n";
    $file = UploadedFile::fake()->createWithContent('vessels.csv', $csv);

    $this->post('/settings/master-data/vessels/import', [
        'file' => $file,
    ])->assertRedirect(route('settings.master-data.vessels.index'));

    $this->assertDatabaseHas('vessels', [
        'name' => 'SAPURA 1200',
        'grt' => '5000.00',
        'bhp' => 9000,
    ]);
});

test('deleting a vessel is blocked when referenced by sea service records', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'VSD',
        'name' => 'Vessel Delete Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'VSD',
        'name' => 'Vessel Delete Currency',
        'symbol' => 'D$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Vessel Delete Co',
        'slug' => 'vessel-delete-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $vesselType = VesselType::query()->create(['name' => 'TUG', 'is_active' => true]);
    $vessel = Vessel::query()->create([
        'name' => 'MV In Use',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    EmployeeSeaService::factory()->forEmployee($employee)->create([
        'vessel_type_id' => $vesselType->id,
        'vessel_id' => $vessel->id,
    ]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.vessels.view',
        'settings.master-data.vessels.delete',
    ]);

    $this->from('/settings/master-data/vessels')
        ->delete("/settings/master-data/vessels/{$vessel->id}")
        ->assertRedirect(route('settings.master-data.vessels.index'))
        ->assertSessionHasErrors('name');

    expect(Vessel::query()->find($vessel->id))->not->toBeNull();
});

test('deleting a vessel is blocked when referenced by crew assignment records', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'VDP',
        'name' => 'Vessel Deployment Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'VDP',
        'name' => 'Vessel Deployment Currency',
        'symbol' => 'P$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Vessel Deployment Co',
        'slug' => 'vessel-deployment-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $rank = Rank::query()->create(['name' => 'VDP Rank', 'is_active' => true]);
    $vesselType = VesselType::query()->create(['name' => 'OSV', 'is_active' => true]);
    $vessel = Vessel::query()->create([
        'name' => 'Deployed Vessel',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.vessels.view',
        'settings.master-data.vessels.delete',
    ]);

    $this->from('/settings/master-data/vessels')
        ->delete("/settings/master-data/vessels/{$vessel->id}")
        ->assertRedirect(route('settings.master-data.vessels.index'))
        ->assertSessionHasErrors('name');
});

test('authorized users can store vessel identification and certificate', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'VCF',
        'name' => 'Vessel Cert Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'VCF',
        'name' => 'Vessel Cert Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Vessel Cert Co',
        'slug' => 'vessel-cert-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $vesselType = VesselType::query()->create([
        'name' => 'PSV',
        'is_active' => true,
    ]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.vessels.view',
        'settings.master-data.vessels.create',
        'settings.master-data.vessels.update',
    ]);

    $certificate = UploadedFile::fake()->create('vessel-cert.pdf', 120, 'application/pdf');

    $this->post('/settings/master-data/vessels', [
        'name' => 'MV Certificate',
        'vessel_type_id' => $vesselType->id,
        'grt' => 3200,
        'bhp' => 8000,
        'official_no' => 'OFF-1001',
        'call_sign' => 'A6XYZ',
        'imo_no' => '9123456',
        'certificate' => $certificate,
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.vessels.index'));

    $vessel = Vessel::query()->where('name', 'MV Certificate')->first();
    expect($vessel)->not->toBeNull()
        ->and($vessel->official_no)->toBe('OFF-1001')
        ->and($vessel->call_sign)->toBe('A6XYZ')
        ->and($vessel->imo_no)->toBe('9123456')
        ->and($vessel->certificate_path)->not->toBeNull()
        ->and($vessel->certificate_original_filename)->toBe('vessel-cert.pdf')
        ->and($vessel->certificate_mime_type)->not->toBeNull()
        ->and($vessel->certificate_checksum)->not->toBe('');

    Storage::disk('public')->assertExists($vessel->certificate_path);

    $this->get('/settings/master-data/vessels')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/vessels')
            ->has('vessels', 1)
            ->has('pagination')
            ->where('pagination.total', 1)
            ->where('search', '')
            ->where('vessels.0.official_no', 'OFF-1001')
            ->where('vessels.0.call_sign', 'A6XYZ')
            ->where('vessels.0.imo_no', '9123456')
            ->where('vessels.0.certificate_original_filename', 'vessel-cert.pdf')
            ->whereNot('vessels.0.certificate_url', null)
        );

    $previousPath = $vessel->certificate_path;

    $this->put("/settings/master-data/vessels/{$vessel->id}", [
        'name' => 'MV Certificate',
        'vessel_type_id' => $vesselType->id,
        'grt' => 3200,
        'bhp' => 8000,
        'official_no' => 'OFF-1001',
        'call_sign' => 'A6XYZ',
        'imo_no' => '9123456',
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.vessels.index'));

    $vessel->refresh();
    expect($vessel->certificate_path)->toBe($previousPath);

    $replacement = UploadedFile::fake()->create('vessel-cert-v2.pdf', 140, 'application/pdf');

    $this->put("/settings/master-data/vessels/{$vessel->id}", [
        'name' => 'MV Certificate',
        'vessel_type_id' => $vesselType->id,
        'grt' => 3200,
        'bhp' => 8000,
        'official_no' => 'OFF-2002',
        'call_sign' => 'A6ABC',
        'imo_no' => '9654321',
        'certificate' => $replacement,
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.vessels.index'));

    $vessel->refresh();
    expect($vessel->official_no)->toBe('OFF-2002')
        ->and($vessel->call_sign)->toBe('A6ABC')
        ->and($vessel->imo_no)->toBe('9654321')
        ->and($vessel->certificate_original_filename)->toBe('vessel-cert-v2.pdf')
        ->and($vessel->certificate_path)->not->toBe($previousPath);

    Storage::disk('public')->assertExists($vessel->certificate_path);
    Storage::disk('public')->assertMissing($previousPath);
});

test('authorized users can view vessel details page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'VSH',
        'name' => 'Vessel Show Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'VSH',
        'name' => 'Vessel Show Currency',
        'symbol' => 'S$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Vessel Show Co',
        'slug' => 'vessel-show-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $vesselType = VesselType::query()->create([
        'name' => 'AHTS Show',
        'is_active' => true,
    ]);

    $vessel = Vessel::query()->create([
        'name' => 'MV Detail',
        'vessel_type_id' => $vesselType->id,
        'grt' => 1200,
        'bhp' => 5000,
        'official_no' => 'OFF-SHOW',
        'call_sign' => 'A6SHOW',
        'imo_no' => '9000001',
        'is_active' => true,
    ]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.vessels.view',
        'settings.master-data.vessels.update',
        'audit.view',
    ]);

    $this->get("/settings/master-data/vessels/{$vessel->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/vessel')
            ->where('vessel.id', $vessel->id)
            ->where('vessel.name', 'MV Detail')
            ->where('vessel.official_no', 'OFF-SHOW')
            ->where('vessel.call_sign', 'A6SHOW')
            ->where('vessel.imo_no', '9000001')
            ->where('summary.manning_ranks', 0)
            ->where('summary.sea_services', 0)
            ->where('summary.active_crew', 0)
            ->where('can.update', true)
            ->where('can_view_audit', true)
            ->has('recent_activity')
            ->has('vessel_types')
        );
});

test('guests cannot view vessel details page', function () {
    $vesselType = VesselType::query()->create([
        'name' => 'Guest Type',
        'is_active' => true,
    ]);

    $vessel = Vessel::query()->create([
        'name' => 'Guest Vessel',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $this->get("/settings/master-data/vessels/{$vessel->id}")
        ->assertRedirect(route('login'));
});
