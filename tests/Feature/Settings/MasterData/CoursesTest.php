<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Course;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Http\UploadedFile;

test('guests cannot access courses page', function () {
    $this->get('/settings/master-data/courses')->assertRedirect(route('login'));
});

test('authorized users can view, create, update, and delete courses', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'CRS',
        'name' => 'Testland Courses',
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CRS',
        'name' => 'Test Currency Courses',
        'symbol' => 'C$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme Courses',
        'slug' => 'acme-courses',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.courses.view',
        'settings.master-data.courses.create',
        'settings.master-data.courses.update',
        'settings.master-data.courses.delete',
    ]);

    $this->get('/settings/master-data/courses')->assertOk();

    $this->post('/settings/master-data/courses', [
        'name' => 'STCW Basic Safety',
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.courses.index'));

    $id = Course::query()->where('name', 'STCW Basic Safety')->value('id');
    expect($id)->not->toBeNull();

    $this->put("/settings/master-data/courses/{$id}", [
        'name' => 'Advanced Fire Fighting',
        'is_active' => false,
    ])->assertRedirect(route('settings.master-data.courses.index'));

    $this->assertDatabaseHas('courses', [
        'id' => $id,
        'name' => 'Advanced Fire Fighting',
        'is_active' => 0,
    ]);

    $this->delete("/settings/master-data/courses/{$id}")
        ->assertRedirect(route('settings.master-data.courses.index'));

    $this->assertSoftDeleted('courses', ['id' => $id]);
});

test('authorized users can download template and import courses from csv', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'CRI',
        'name' => 'Course Importland',
        'dial_code' => '+992',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CRI',
        'name' => 'Course Import Currency',
        'symbol' => 'R$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Course Import Co',
        'slug' => 'course-import-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.courses.view',
        'settings.master-data.courses.create',
    ]);

    $this->get('/settings/master-data/courses/import/template')
        ->assertOk()
        ->assertDownload();

    $csvContent = "name,is_active\nLegacy Course,no\nNew Course,yes\n";

    $this->post('/settings/master-data/courses/import', [
        'file' => UploadedFile::fake()->createWithContent('courses.csv', $csvContent),
    ])->assertRedirect(route('settings.master-data.courses.index'));

    expect(Course::query()->where('name', 'Legacy Course')->value('is_active'))->toBe(false);
    expect(Course::query()->where('name', 'New Course')->value('is_active'))->toBe(true);
});
