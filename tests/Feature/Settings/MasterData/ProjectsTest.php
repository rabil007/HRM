<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Http\UploadedFile;

test('guests cannot access projects page', function () {
    $this->get('/settings/master-data/projects')->assertRedirect(route('login'));
});

test('authorized users can view, create, update, and delete projects', function () {
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
        'settings.master-data.projects.view',
        'settings.master-data.projects.create',
        'settings.master-data.projects.update',
        'settings.master-data.projects.delete',
    ]);

    $this->get('/settings/master-data/projects')->assertOk();

    $this->post('/settings/master-data/projects', [
        'title' => 'North Field',
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.projects.index'));

    $id = Project::query()->where('title', 'North Field')->value('id');
    expect($id)->not->toBeNull();

    $this->put("/settings/master-data/projects/{$id}", [
        'title' => 'South Field',
        'is_active' => false,
    ])->assertRedirect(route('settings.master-data.projects.index'));

    $this->assertDatabaseHas('projects', [
        'id' => $id,
        'title' => 'South Field',
        'is_active' => 0,
    ]);

    $this->delete("/settings/master-data/projects/{$id}")
        ->assertRedirect(route('settings.master-data.projects.index'));

    $this->assertSoftDeleted('projects', ['id' => $id]);
});

test('authorized users can download csv template and import projects', function () {
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
        'settings.master-data.projects.view',
        'settings.master-data.projects.create',
    ]);

    $this->get('/settings/master-data/projects/import/template')
        ->assertOk()
        ->assertDownload();

    $csvContent = "title,is_active\nAlpha Platform,no\nBeta Field,yes\n";

    $this->post('/settings/master-data/projects/import', [
        'file' => UploadedFile::fake()->createWithContent('projects.csv', $csvContent),
    ])->assertRedirect('/settings/master-data/projects');

    expect(Project::query()->where('title', 'Alpha Platform')->value('is_active'))->toBe(false);
    expect(Project::query()->where('title', 'Beta Field')->value('is_active'))->toBe(true);
});
