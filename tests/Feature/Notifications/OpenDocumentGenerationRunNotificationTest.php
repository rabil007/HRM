<?php

use App\Enums\DocumentGenerationTemplateStatus;
use App\Models\Company;
use App\Models\DocumentGenerationRun;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
});

/**
 * @return array{company: Company, otherCompany: Company, user: User, other: User, run: DocumentGenerationRun}
 */
function makeOpenGenerationRunFixtures(): array
{
    $user = User::factory()->create(['status' => 'active']);
    $other = User::factory()->create(['status' => 'active']);
    test()->actingAs($user);
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);
    $otherCompany = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $template->update(['published_version_id' => $version->id]);

    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'completed',
        'total_targeted' => 1,
        'correlation_id' => (string) Str::uuid(),
        'triggered_by' => $user->id,
    ]);

    return compact('company', 'otherCompany', 'user', 'other', 'run');
}

test('triggered user can open the generation run and lands on the custom template', function () {
    ['company' => $company, 'otherCompany' => $otherCompany, 'user' => $user, 'run' => $run] = makeOpenGenerationRunFixtures();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $otherCompany->id])
        ->get(route('notifications.documents.generation-runs.open', $run))
        ->assertRedirect(route('organization.documents.generate', [
            'document_type_key' => 'custom_'.$run->document_generation_template_id,
        ]));

    expect(session('current_company_id'))->toBe($company->id);
});

test('guest is redirected to login', function () {
    ['run' => $run] = makeOpenGenerationRunFixtures();

    $this->get(route('notifications.documents.generation-runs.open', $run))
        ->assertRedirect();
});

test('another user cannot open the run', function () {
    ['company' => $company, 'other' => $other, 'run' => $run] = makeOpenGenerationRunFixtures();
    grantCompanyPermissions($other, $company, ['bulk_documents.view']);

    $this->actingAs($other)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('notifications.documents.generation-runs.open', $run))
        ->assertNotFound();
});

test('triggered user without bulk_documents.view is forbidden', function () {
    $user = User::factory()->create(['status' => 'active']);
    $this->actingAs($user);
    $company = setupBulkDocumentsCompany($user, ['documents.view']);
    $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
        'status' => DocumentGenerationTemplateStatus::Active,
    ]);
    $version = DocumentGenerationTemplateVersion::factory()->forTemplate($template)->published()->create();
    $run = DocumentGenerationRun::query()->create([
        'company_id' => $company->id,
        'document_generation_template_id' => $template->id,
        'document_generation_template_version_id' => $version->id,
        'status' => 'completed',
        'total_targeted' => 1,
        'correlation_id' => (string) Str::uuid(),
        'triggered_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('notifications.documents.generation-runs.open', $run))
        ->assertForbidden();
});

test('inactive membership is rejected', function () {
    ['company' => $company, 'user' => $user, 'run' => $run] = makeOpenGenerationRunFixtures();

    DB::table('company_user')
        ->where('company_id', $company->id)
        ->where('user_id', $user->id)
        ->update(['status' => 'inactive']);

    $this->actingAs($user)
        ->get(route('notifications.documents.generation-runs.open', $run))
        ->assertForbidden();
});
