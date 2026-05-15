<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Rank;
use App\Models\User;
use Illuminate\Http\UploadedFile;

test('guests cannot access ranks page', function () {
    $this->get('/settings/master-data/ranks')->assertRedirect(route('login'));
});

test('authorized users can view, create, update, and delete ranks', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'RNK',
        'name' => 'Rank Testland',
        'dial_code' => '+996',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'RNK',
        'name' => 'Rank Currency',
        'symbol' => 'R$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Rank Co',
        'slug' => 'rank-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.ranks.view',
        'settings.master-data.ranks.create',
        'settings.master-data.ranks.update',
        'settings.master-data.ranks.delete',
    ]);

    $this->get('/settings/master-data/ranks')->assertOk();

    $this->post('/settings/master-data/ranks', [
        'name' => 'Captain',
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.ranks.index'));

    $id = Rank::query()->where('name', 'Captain')->value('id');
    expect($id)->not->toBeNull();

    $this->put("/settings/master-data/ranks/{$id}", [
        'name' => 'Chief Officer',
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.ranks.index'));

    $this->assertDatabaseHas('ranks', [
        'id' => $id,
        'name' => 'Chief Officer',
    ]);

    $this->delete("/settings/master-data/ranks/{$id}")
        ->assertRedirect(route('settings.master-data.ranks.index'));

    $this->assertDatabaseMissing('ranks', ['id' => $id]);
});

test('authorized users can download template and import ranks from csv', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'RIM',
        'name' => 'Importland',
        'dial_code' => '+995',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'RIM',
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
        'settings.master-data.ranks.view',
        'settings.master-data.ranks.create',
    ]);

    $this->get('/settings/master-data/ranks/import/template')
        ->assertOk()
        ->assertDownload();

    $csvContent = "name,is_active\nBosun,no\nAble Seaman,yes\n";

    $this->post('/settings/master-data/ranks/import', [
        'file' => UploadedFile::fake()->createWithContent('ranks.csv', $csvContent),
    ])->assertRedirect(route('settings.master-data.ranks.index'));

    expect(Rank::query()->where('name', 'Bosun')->value('is_active'))->toBe(false);
    expect(Rank::query()->where('name', 'Able Seaman')->value('is_active'))->toBe(true);
});
