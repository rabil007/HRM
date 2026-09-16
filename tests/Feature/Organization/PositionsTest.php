<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

function createPositionTestCompany(string $name, string $code): Company
{
    $country = Country::query()->create([
        'code' => $code,
        'name' => "{$name} Country",
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => $code,
        'name' => "{$name} Currency",
        'symbol' => '$',
        'is_active' => true,
    ]);

    return Company::query()->create([
        'name' => $name,
        'slug' => strtolower(str_replace(' ', '-', $name)),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}

test('guests cannot access positions page', function () {
    $this->get('/organization/positions')->assertRedirect(route('login'));
});

test('authenticated users can view positions page', function () {
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

    grantCompanyPermissions($user, $company, ['positions.view']);

    $this->get('/organization/positions')->assertOk();
});

test('authenticated users can view a position details page', function () {
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

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Engineering',
        'code' => 'ENG',
        'status' => 'active',
    ]);

    $position = Position::query()->create([
        'company_id' => $company->id,
        'department_id' => $department->id,
        'title' => 'Software Engineer',
        'grade' => 'G5',
        'min_salary' => 1000,
        'max_salary' => 2000,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['positions.view']);

    $this->get("/organization/positions/{$position->id}")->assertOk();
});

test('authenticated users can create, update, and delete a position', function () {
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

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Engineering',
        'code' => 'ENG',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['positions.create', 'positions.update', 'positions.delete', 'positions.view']);

    $this->post('/organization/positions', [
        'department_id' => $department->id,
        'title' => 'Software Engineer',
        'grade' => 'G5',
        'min_salary' => 1000,
        'max_salary' => 2000,
        'status' => 'active',
    ])->assertRedirect('/organization/positions');

    $positionId = Position::query()
        ->where('company_id', $company->id)
        ->where('title', 'Software Engineer')
        ->value('id');

    expect($positionId)->not->toBeNull();

    $this->put("/organization/positions/{$positionId}", [
        'department_id' => $department->id,
        'title' => 'Senior Software Engineer',
        'grade' => 'G6',
        'min_salary' => 2000,
        'max_salary' => 4000,
        'status' => 'inactive',
    ])->assertRedirect('/organization/positions');

    $this->assertDatabaseHas('positions', [
        'id' => $positionId,
        'title' => 'Senior Software Engineer',
        'grade' => 'G6',
        'status' => 'inactive',
    ]);

    $activity = Activity::query()
        ->where('company_id', $company->id)
        ->where('subject_type', Position::class)
        ->where('subject_id', $positionId)
        ->where('event', 'updated')
        ->latest('id')
        ->first();
    expect($activity)->not->toBeNull();

    $this->delete("/organization/positions/{$positionId}")->assertRedirect('/organization/positions');
    $this->assertSoftDeleted('positions', ['id' => $positionId]);
});

test('authenticated users can export positions as csv, excel, and pdf', function () {
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

    Position::query()->create([
        'company_id' => $company->id,
        'title' => 'HR Specialist',
        'grade' => 'H1',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['positions.view', 'positions.export']);

    $csv = $this->get('/organization/positions/export?format=csv&search=H1');
    $csv->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv');

    $xlsx = $this->get('/organization/positions/export?format=xlsx&search=H1');
    $xlsx->assertOk();
    expect($xlsx->headers->get('content-type'))->toContain('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $pdf = $this->get('/organization/positions/export?format=pdf&search=H1');
    $pdf->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('application/pdf');
});

test('authenticated users can toggle position status', function () {
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

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Engineering',
        'code' => 'ENG',
        'status' => 'active',
    ]);

    $position = Position::query()->create([
        'company_id' => $company->id,
        'department_id' => $department->id,
        'title' => 'Software Engineer',
        'grade' => 'G5',
        'min_salary' => 1000,
        'max_salary' => 2000,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['positions.update']);

    $this->put("/organization/positions/{$position->id}/status", [
        'status' => 'inactive',
    ])->assertRedirect('/organization/positions');

    $this->assertDatabaseHas('positions', [
        'id' => $position->id,
        'status' => 'inactive',
    ]);
});

test('authorized users can save a position description and private photo attachment', function () {
    Storage::fake('local');
    Storage::fake('public');

    $user = User::factory()->create();
    $company = createPositionTestCompany('Attachment Company', 'ATC');
    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine Operations',
        'code' => 'MOP',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['positions.create', 'positions.view']);

    $this->actingAs($user)->post('/organization/positions', [
        'department_id' => $department->id,
        'title' => 'Deck Supervisor',
        'description' => 'Supervises deck work and safety checks.',
        'status' => 'active',
        'attachment' => UploadedFile::fake()->image('deck-supervisor.jpg'),
    ])->assertRedirect('/organization/positions');

    $position = Position::query()->where('company_id', $company->id)->sole();

    expect($position->description)->toBe('Supervises deck work and safety checks.')
        ->and($position->attachment_original_name)->toBe('deck-supervisor.jpg')
        ->and($position->attachment_path)->toStartWith("position-attachments/{$company->id}/{$position->id}/");

    Storage::disk('local')->assertExists($position->attachment_path);
    Storage::disk('public')->assertMissing($position->attachment_path);

    $this->get("/organization/positions/{$position->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/position')
            ->where('position.description', 'Supervises deck work and safety checks.')
            ->where('position.attachment.original_name', 'deck-supervisor.jpg')
            ->where('position.attachment.is_image', true));

    $preview = $this->get("/organization/positions/{$position->id}/attachment/preview");
    $preview->assertOk();
    expect($preview->headers->get('content-disposition'))->toContain('inline');

    $this->get("/organization/positions/{$position->id}/attachment/download")
        ->assertOk()
        ->assertDownload('deck-supervisor.jpg');
});

test('authorized users can replace and remove a position attachment', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $company = createPositionTestCompany('Replacement Company', 'RPC');
    grantCompanyPermissions($user, $company, ['positions.update']);

    $position = Position::query()->create([
        'company_id' => $company->id,
        'title' => 'Chief Engineer',
        'description' => 'Original description.',
        'status' => 'active',
        'attachment_path' => "position-attachments/{$company->id}/1/old.pdf",
        'attachment_original_name' => 'old.pdf',
        'attachment_mime_type' => 'application/pdf',
        'attachment_size_bytes' => 3,
        'attachment_checksum' => hash('sha256', 'old'),
    ]);
    $oldPath = "position-attachments/{$company->id}/{$position->id}/old.pdf";
    $position->update(['attachment_path' => $oldPath]);
    Storage::disk('local')->put($oldPath, 'old');

    $this->actingAs($user)->put("/organization/positions/{$position->id}", [
        'title' => 'Chief Engineer',
        'description' => 'Updated description.',
        'status' => 'active',
        'attachment' => UploadedFile::fake()->create('job-description.pdf', 100, 'application/pdf'),
    ])->assertRedirect('/organization/positions');

    $position->refresh();
    $replacementPath = $position->attachment_path;

    expect($position->description)->toBe('Updated description.')
        ->and($position->attachment_original_name)->toBe('job-description.pdf');
    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertExists($replacementPath);

    $this->put("/organization/positions/{$position->id}", [
        'title' => 'Chief Engineer',
        'description' => 'Updated description.',
        'status' => 'active',
        'remove_attachment' => true,
    ])->assertRedirect('/organization/positions');

    $position->refresh();

    expect($position->attachment_path)->toBeNull()
        ->and($position->attachment_original_name)->toBeNull();
    Storage::disk('local')->assertMissing($replacementPath);
});

test('position writes reject departments from another company', function () {
    $user = User::factory()->create();
    $company = createPositionTestCompany('Primary Company', 'PRI');
    $otherCompany = createPositionTestCompany('Other Company', 'OTH');
    $otherDepartment = Department::query()->create([
        'company_id' => $otherCompany->id,
        'name' => 'Other Department',
        'code' => 'OTH',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['positions.create']);

    $this->actingAs($user)->post('/organization/positions', [
        'department_id' => $otherDepartment->id,
        'title' => 'Cross Tenant Position',
        'status' => 'active',
    ])->assertSessionHasErrors('department_id');

    $this->assertDatabaseMissing('positions', ['title' => 'Cross Tenant Position']);
});

test('position attachments reject unsupported files and oversized descriptions', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $company = createPositionTestCompany('Validation Company', 'VAL');
    grantCompanyPermissions($user, $company, ['positions.create']);

    $this->actingAs($user)->post('/organization/positions', [
        'title' => 'Invalid Position',
        'description' => str_repeat('x', 5001),
        'status' => 'active',
        'attachment' => UploadedFile::fake()->create(
            'unsafe.exe',
            10,
            'application/x-msdownload',
        ),
    ])->assertSessionHasErrors(['description', 'attachment']);

    $this->assertDatabaseMissing('positions', ['title' => 'Invalid Position']);
    expect(Storage::disk('local')->allFiles('position-attachments'))->toBeEmpty();
});

test('position attachments require view permission and active company ownership', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $company = createPositionTestCompany('Viewer Company', 'VWR');
    $otherCompany = createPositionTestCompany('Hidden Company', 'HDN');
    $position = Position::query()->create([
        'company_id' => $company->id,
        'title' => 'Visible Position',
        'status' => 'active',
        'attachment_path' => "position-attachments/{$company->id}/1/file.pdf",
        'attachment_original_name' => 'file.pdf',
        'attachment_mime_type' => 'application/pdf',
        'attachment_size_bytes' => 3,
        'attachment_checksum' => hash('sha256', 'pdf'),
    ]);
    $path = "position-attachments/{$company->id}/{$position->id}/file.pdf";
    $position->update(['attachment_path' => $path]);
    Storage::disk('local')->put($path, 'pdf');

    grantCompanyPermissions($user, $company, []);

    $this->actingAs($user)
        ->get("/organization/positions/{$position->id}/attachment/download")
        ->assertForbidden();

    grantCompanyPermissions($user, $company, ['positions.view']);
    $outsidePositionPath = "position-attachments/{$company->id}/999/file.pdf";
    $position->update(['attachment_path' => $outsidePositionPath]);
    Storage::disk('local')->put($outsidePositionPath, 'pdf');

    $this->get("/organization/positions/{$position->id}/attachment/download")
        ->assertNotFound();

    $position->update(['attachment_path' => $path]);
    grantCompanyPermissions($user, $otherCompany, ['positions.view']);
    session(['current_company_id' => $otherCompany->id]);

    $this->get("/organization/positions/{$position->id}/attachment/download")
        ->assertNotFound();
});
