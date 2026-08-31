<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\DocumentType;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot access document types configuration', function () {
    $this->get('/organization/documents/configuration')->assertRedirect(route('login'));
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

    $this->get('/organization/documents/configuration')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/configuration/document-types'));

    $this->get('/settings/master-data/document-types')
        ->assertRedirect('/organization/documents/configuration');

    $this->post('/settings/master-data/document-types', [
        'title' => 'Passport Copy',
        'is_active' => true,
    ])->assertRedirect('/organization/documents/configuration');

    $docId = DocumentType::query()->where('title', 'Passport Copy')->value('id');
    expect($docId)->not->toBeNull();

    $this->put("/settings/master-data/document-types/{$docId}", [
        'title' => 'Passport Copy Updated',
        'is_active' => false,
    ])->assertRedirect('/organization/documents/configuration');

    $this->assertDatabaseHas('document_types', [
        'id' => $docId,
        'title' => 'Passport Copy Updated',
        'is_active' => 0,
    ]);

    $this->delete("/settings/master-data/document-types/{$docId}")
        ->assertRedirect('/organization/documents/configuration');

    $this->assertSoftDeleted('document_types', ['id' => $docId]);
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
    ])->assertRedirect('/organization/documents/configuration');

    expect(DocumentType::query()->where('title', 'Licence Card')->value('is_active'))->toBe(false);
    expect(DocumentType::query()->where('title', 'Work Permit')->value('is_active'))->toBe(true);
});
