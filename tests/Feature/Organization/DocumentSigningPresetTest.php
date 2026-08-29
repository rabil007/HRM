<?php

use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentSigningPresetStatus;
use App\Models\Company;
use App\Models\DocumentSigningPreset;
use App\Models\User;
use App\Support\Documents\Signing\Actions\DeleteDocumentSigningPreset;
use App\Support\Documents\Signing\Actions\StartDocumentSigningFlow;
use App\Support\Documents\Signing\Actions\StoreDocumentSigningPreset;
use App\Support\Documents\Signing\DocumentSigningPresetPresenter;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/document-recipient-request-fixtures.php';
require_once __DIR__.'/../../Support/document-workflow-fixtures.php';
require_once __DIR__.'/../../Support/spatie.php';

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
});

function grantSigningPresetPermissions(User $user, Company $company, array $permissions): void
{
    foreach ($permissions as $permission) {
        giveCompanyPermission($user, $company, $permission);
    }
}

test('users without signing-presets.view cannot access preset index', function () {
    $company = makeDocumentFixtures()['company'];
    $user = User::factory()->create();
    grantCompanyPermissions($user, $company, ['documents.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.signing-presets'))
        ->assertForbidden();
});

test('can create subject only signing preset', function () {
    $company = makeDocumentFixtures()['company'];
    $admin = User::factory()->create();
    grantSigningPresetPermissions($admin, $company, [
        'documents.signing-presets.create',
        'documents.signing-presets.view',
    ]);

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.signing-presets.store'), [
            'name' => 'Employee only',
            'description' => null,
            'steps' => [
                ['recipient_role' => 'subject'],
            ],
        ])
        ->assertRedirect();

    $preset = DocumentSigningPreset::query()->where('company_id', $company->id)->first();
    expect($preset)->not->toBeNull()
        ->and($preset->status)->toBe(DocumentSigningPresetStatus::Active)
        ->and($preset->steps)->toHaveCount(1)
        ->and($preset->steps->first()->recipient_role)->toBe(DocumentRecipientRole::Subject);
});

test('can create subject manager company signing preset', function () {
    $company = makeDocumentFixtures()['company'];
    $admin = User::factory()->create();
    $signatory = User::factory()->create(['status' => 'active']);
    grantSigningPresetPermissions($admin, $company, ['documents.signing-presets.create']);
    giveCompanyPermission($signatory, $company, 'documents.recipient-requests.respond');

    $preset = app(StoreDocumentSigningPreset::class)->handle(
        $admin,
        $company->id,
        'Full chain',
        null,
        [
            ['recipient_role' => 'subject'],
            ['recipient_role' => 'manager'],
            ['recipient_role' => 'company_signatory', 'target_user_id' => $signatory->id],
        ],
    );

    expect($preset->steps)->toHaveCount(3)
        ->and($preset->steps->pluck('recipient_role')->map->value->all())
        ->toBe(['subject', 'manager', 'company_signatory']);
});

test('invalid order and duplicate roles are rejected', function () {
    $company = makeDocumentFixtures()['company'];
    $admin = User::factory()->create();
    $signatory = User::factory()->create(['status' => 'active']);
    grantSigningPresetPermissions($admin, $company, ['documents.signing-presets.create']);
    giveCompanyPermission($signatory, $company, 'documents.recipient-requests.respond');

    expect(fn () => app(StoreDocumentSigningPreset::class)->handle(
        $admin,
        $company->id,
        'Bad order',
        null,
        [
            ['recipient_role' => 'subject'],
            ['recipient_role' => 'company_signatory', 'target_user_id' => $signatory->id],
            ['recipient_role' => 'manager'],
        ],
    ))->toThrow(ValidationException::class);

    expect(fn () => app(StoreDocumentSigningPreset::class)->handle(
        $admin,
        $company->id,
        'Dup roles',
        null,
        [
            ['recipient_role' => 'subject'],
            ['recipient_role' => 'subject'],
        ],
    ))->toThrow(ValidationException::class);
});

test('company signer from another company is rejected', function () {
    $companyA = makeDocumentFixtures()['company'];
    $companyB = makeDocumentFixtures()['company'];
    $admin = User::factory()->create();
    $signatoryB = User::factory()->create(['status' => 'active']);
    grantSigningPresetPermissions($admin, $companyA, ['documents.signing-presets.create']);
    giveCompanyPermission($signatoryB, $companyB, 'documents.recipient-requests.respond');

    expect(fn () => app(StoreDocumentSigningPreset::class)->handle(
        $admin,
        $companyA->id,
        'Cross company',
        null,
        [
            ['recipient_role' => 'subject'],
            ['recipient_role' => 'company_signatory', 'target_user_id' => $signatoryB->id],
        ],
    ))->toThrow(ValidationException::class);
});

test('company signer without respond permission is rejected', function () {
    $company = makeDocumentFixtures()['company'];
    $admin = User::factory()->create();
    $signatory = User::factory()->create(['status' => 'active']);
    grantSigningPresetPermissions($admin, $company, ['documents.signing-presets.create']);
    grantCompanyPermissions($signatory, $company, ['documents.view']);

    expect(fn () => app(StoreDocumentSigningPreset::class)->handle(
        $admin,
        $company->id,
        'No respond',
        null,
        [
            ['recipient_role' => 'subject'],
            ['recipient_role' => 'company_signatory', 'target_user_id' => $signatory->id],
        ],
    ))->toThrow(ValidationException::class);
});

test('used signing preset cannot be deleted', function () {
    $fixtures = makeRecipientFixturesWithSignaturePlacement([
        'schema_version' => 1,
        'placements' => [
            [
                'id' => 'subject_signature',
                'type' => 'signature',
                'role' => 'subject',
                'page' => 1,
                'x' => 0.1,
                'y' => 0.75,
                'width' => 0.25,
                'height' => 0.08,
                'required' => true,
            ],
        ],
    ]);
    $company = $fixtures['company'];
    $admin = User::factory()->create();
    grantSigningPresetPermissions($admin, $company, [
        'documents.signing-presets.create',
        'documents.signing-presets.delete',
    ]);
    giveCompanyPermission($admin, $company, 'documents.recipient-requests.create');

    $preset = app(StoreDocumentSigningPreset::class)->handle(
        $admin,
        $company->id,
        'Used preset',
        null,
        [['recipient_role' => 'subject']],
    );

    app(StartDocumentSigningFlow::class)->handle(
        $fixtures['document'],
        $admin,
        $company->id,
        $preset->id,
    );

    expect(fn () => app(DeleteDocumentSigningPreset::class)->handle(
        $preset,
        $admin,
        $company->id,
    ))->toThrow(ValidationException::class);
});

test('blank step labels are stored as null not generated text', function () {
    $company = makeDocumentFixtures()['company'];
    $admin = User::factory()->create();
    grantSigningPresetPermissions($admin, $company, ['documents.signing-presets.create']);

    $preset = app(StoreDocumentSigningPreset::class)->handle(
        $admin,
        $company->id,
        'Blank labels',
        null,
        [
            ['recipient_role' => 'subject'],
            ['recipient_role' => 'manager'],
        ],
    );

    expect($preset->steps->pluck('step_label')->all())->toBe([null, null]);

    $presented = app(DocumentSigningPresetPresenter::class)
        ->detail($preset);

    expect($presented['steps'][0]['step_label'])->toBeNull()
        ->and($presented['steps'][0]['display_label'])->toBe('Employee')
        ->and($presented['steps'][1]['display_label'])->toBe('Department Manager');
});
