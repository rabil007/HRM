<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Http\UploadedFile;

test('guests cannot access vessels page', function () {
    $this->get('/settings/master-data/vessels')->assertRedirect(route('login'));
});

test('authorized users can view, create, update, and delete vessels', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'VSL',
        'name' => 'Vessel Testland',
        'dial_code' => '+998',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'VSL',
        'name' => 'Vessel Currency',
        'symbol' => 'V$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Vessel Co',
        'slug' => 'vessel-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.vessels.view',
        'settings.master-data.vessels.create',
        'settings.master-data.vessels.update',
        'settings.master-data.vessels.delete',
    ]);

    $this->get('/settings/master-data/vessels')->assertOk();

    $this->post('/settings/master-data/vessels', [
        'name' => 'OSV Aurora',
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.vessels.index'));

    $id = Vessel::query()->where('name', 'OSV Aurora')->value('id');
    expect($id)->not->toBeNull();

    $this->put("/settings/master-data/vessels/{$id}", [
        'name' => 'OSV Aurora II',
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.vessels.index'));

    $this->assertDatabaseHas('vessels', [
        'id' => $id,
        'name' => 'OSV Aurora II',
    ]);

    $this->delete("/settings/master-data/vessels/{$id}")
        ->assertRedirect(route('settings.master-data.vessels.index'));

    $this->assertDatabaseMissing('vessels', ['id' => $id]);
});

test('authorized users can download template and import vessels from csv', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'VIM',
        'name' => 'Importland',
        'dial_code' => '+997',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'VIM',
        'name' => 'Import Currency',
        'symbol' => 'I$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Import Co',
        'slug' => 'import-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.vessels.view',
        'settings.master-data.vessels.create',
    ]);

    $this->get('/settings/master-data/vessels/import/template')
        ->assertOk()
        ->assertDownload();

    $csvContent = "name,is_active\nHarbour Star,no\nPacific Runner,yes\n";

    $this->post('/settings/master-data/vessels/import', [
        'file' => UploadedFile::fake()->createWithContent('vessels.csv', $csvContent),
    ])->assertRedirect(route('settings.master-data.vessels.index'));

    expect(Vessel::query()->where('name', 'Harbour Star')->value('is_active'))->toBe(false);
    expect(Vessel::query()->where('name', 'Pacific Runner')->value('is_active'))->toBe(true);
});
