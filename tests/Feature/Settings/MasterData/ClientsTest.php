<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Http\UploadedFile;

test('guests cannot access clients page', function () {
    $this->get('/settings/master-data/clients')->assertRedirect(route('login'));
});

test('authorized users can view, create, update, and delete clients', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'CLN',
        'name' => 'Client Testland',
        'dial_code' => '+994',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CLN',
        'name' => 'Client Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Client Co',
        'slug' => 'client-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.view',
        'settings.master-data.clients.create',
        'settings.master-data.clients.update',
        'settings.master-data.clients.delete',
    ]);

    $this->get('/settings/master-data/clients')->assertOk();

    $this->post('/settings/master-data/clients', [
        'name' => 'Charter Alpha',
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.clients.index'));

    $id = Client::query()->where('name', 'Charter Alpha')->value('id');
    expect($id)->not->toBeNull();

    $this->put("/settings/master-data/clients/{$id}", [
        'name' => 'Charter Beta',
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.clients.index'));

    $this->assertDatabaseHas('clients', [
        'id' => $id,
        'name' => 'Charter Beta',
    ]);

    $this->delete("/settings/master-data/clients/{$id}")
        ->assertRedirect(route('settings.master-data.clients.index'));

    $this->assertDatabaseMissing('clients', ['id' => $id]);
});

test('authorized users can download template and import clients from csv', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'CLI',
        'name' => 'Client Importland',
        'dial_code' => '+993',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CLI',
        'name' => 'Client Import Currency',
        'symbol' => 'I$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Client Import Co',
        'slug' => 'client-import-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.view',
        'settings.master-data.clients.create',
    ]);

    $this->get('/settings/master-data/clients/import/template')
        ->assertOk()
        ->assertDownload();

    $csvContent = "name,is_active\nSmall Co,no\nBig Co,yes\n";

    $this->post('/settings/master-data/clients/import', [
        'file' => UploadedFile::fake()->createWithContent('clients.csv', $csvContent),
    ])->assertRedirect(route('settings.master-data.clients.index'));

    expect(Client::query()->where('name', 'Small Co')->value('is_active'))->toBe(false);
    expect(Client::query()->where('name', 'Big Co')->value('is_active'))->toBe(true);
});
