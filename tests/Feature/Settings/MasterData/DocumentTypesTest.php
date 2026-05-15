<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\DocumentType;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Http\UploadedFile;

test('guests cannot access document types master data page', function () {
    $this->get('/settings/master-data/document-types')->assertRedirect(route('login'));
});

test('authorized users can manage document types', function () {
    $this->seed(PermissionsSeeder::class);

    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TST',
        'name' => 'Testland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TST',
        'name' => 'Test Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.document-types.view',
        'settings.master-data.document-types.create',
        'settings.master-data.document-types.update',
        'settings.master-data.document-types.delete',
    ]);

    $this->get('/settings/master-data/document-types')->assertOk();

    $this->post('/settings/master-data/document-types', [
        'title' => 'Passport Copy',
        'is_active' => true,
    ])->assertRedirect('/settings/master-data/document-types');

    $docId = DocumentType::query()->where('title', 'Passport Copy')->value('id');
    expect($docId)->not->toBeNull();

    $this->put("/settings/master-data/document-types/{$docId}", [
        'title' => 'Passport Copy Updated',
        'is_active' => false,
    ])->assertRedirect('/settings/master-data/document-types');

    $this->assertDatabaseHas('document_types', [
        'id' => $docId,
        'title' => 'Passport Copy Updated',
        'is_active' => 0,
    ]);

    $this->delete("/settings/master-data/document-types/{$docId}")
        ->assertRedirect('/settings/master-data/document-types');

    $this->assertDatabaseMissing('document_types', ['id' => $docId]);
});

test('authorized users can download csv template and import document types', function () {
    $this->seed(PermissionsSeeder::class);

    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TST',
        'name' => 'Testland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TST',
        'name' => 'Test Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.document-types.view',
        'settings.master-data.document-types.create',
    ]);

    $this->get('/settings/master-data/document-types/import/template')
        ->assertOk()
        ->assertDownload();

    $csvContent = "title,is_active\nLicence Card,no\nWork Permit,yes\n";

    $this->post('/settings/master-data/document-types/import', [
        'file' => UploadedFile::fake()->createWithContent('types.csv', $csvContent),
    ])->assertRedirect('/settings/master-data/document-types');

    expect(DocumentType::query()->where('title', 'Licence Card')->value('is_active'))->toBe(false);
    expect(DocumentType::query()->where('title', 'Work Permit')->value('is_active'))->toBe(true);
});
