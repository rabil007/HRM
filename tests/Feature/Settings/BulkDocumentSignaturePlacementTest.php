<?php

use App\Models\Employee;
use App\Models\User;
use App\Services\BulkDocuments\RendersEmployeeDocumentPdf;
use App\Services\SalaryDeclaration\SalaryDeclarationPdfRenderer;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use App\Support\BulkDocuments\SalaryDeclarationSignaturePlacements;
use Database\Seeders\PermissionsSeeder;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
});

test('authorized user can fetch placement json', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, ['settings.application.view']);

    $this->actingAs($user)
        ->getJson(route('application.esign-placement.show', 'salary_declaration'))
        ->assertSuccessful()
        ->assertJsonPath('placement.page', 1)
        ->assertJsonPath('is_custom', false);
});

test('authorized user can preview placement pdf', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, ['settings.application.view']);

    $renderer = new class implements RendersEmployeeDocumentPdf
    {
        public function render(Employee $employee, int $companyId, ?array $signature = null, bool $showPlacementGuides = false): string
        {
            return minimalPdfBytes();
        }
    };

    app()->instance(SalaryDeclarationPdfRenderer::class, $renderer);

    $this->actingAs($user)
        ->get(route('application.esign-preview', 'salary_declaration'))
        ->assertSuccessful()
        ->assertHeader('content-type', 'application/pdf');
});

test('authorized user can save placement coordinates', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'settings.application.view',
        'settings.application.update',
    ]);

    $payload = [
        'page' => 1,
        'canvas_width' => 800,
        'canvas_height' => 1131,
        'signature' => [
            'left' => 40,
            'top' => 500,
            'width' => 280,
            'height' => 60,
        ],
        'date' => [
            'left' => 40,
            'top' => 580,
            'width' => 160,
            'height' => 30,
        ],
        'signature_ar' => [
            'left' => 480,
            'top' => 500,
            'width' => 280,
            'height' => 60,
        ],
        'date_ar' => [
            'left' => 480,
            'top' => 580,
            'width' => 160,
            'height' => 30,
        ],
    ];

    $this->actingAs($user)
        ->putJson(route('application.esign-placement.update', 'salary_declaration'), $payload)
        ->assertSuccessful()
        ->assertJsonPath('placement.page', 1)
        ->assertJsonStructure([
            'placement' => ['page', 'overlay', 'stamps'],
            'message',
        ]);

    expect(BulkDocumentTypeRegistry::resolveSignaturePlacements('salary_declaration'))
        ->not->toBe(SalaryDeclarationSignaturePlacements::config());
});

test('authorized user can reset placement to defaults', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'settings.application.view',
        'settings.application.update',
    ]);

    $this->actingAs($user)
        ->putJson(route('application.esign-placement.update', 'salary_declaration'), [
            'page' => 1,
            'canvas_width' => 800,
            'canvas_height' => 1131,
            'signature' => [
                'left' => 40,
                'top' => 500,
                'width' => 280,
                'height' => 60,
            ],
            'date' => [
                'left' => 40,
                'top' => 580,
                'width' => 160,
                'height' => 30,
            ],
            'signature_ar' => [
                'left' => 480,
                'top' => 500,
                'width' => 280,
                'height' => 60,
            ],
            'date_ar' => [
                'left' => 480,
                'top' => 580,
                'width' => 160,
                'height' => 30,
            ],
        ])
        ->assertSuccessful();

    $response = $this->actingAs($user)
        ->deleteJson(route('application.esign-placement.destroy', 'salary_declaration'))
        ->assertSuccessful();

    expect($response->json('placement'))->toEqual(
        SalaryDeclarationSignaturePlacements::config(),
    );
});

test('guest cannot access placement endpoints', function () {
    $this->getJson(route('application.esign-placement.show', 'salary_declaration'))
        ->assertUnauthorized();

    $this->get(route('application.esign-preview', 'salary_declaration'))
        ->assertRedirect();
});

test('user without permission cannot update placement', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, ['settings.application.view']);

    $this->actingAs($user)
        ->putJson(route('application.esign-placement.update', 'salary_declaration'), [
            'page' => 1,
            'canvas_width' => 800,
            'canvas_height' => 1131,
            'signature' => [
                'left' => 40,
                'top' => 500,
                'width' => 280,
                'height' => 60,
            ],
            'date' => [
                'left' => 40,
                'top' => 580,
                'width' => 160,
                'height' => 30,
            ],
            'signature_ar' => [
                'left' => 480,
                'top' => 500,
                'width' => 280,
                'height' => 60,
            ],
            'date_ar' => [
                'left' => 480,
                'top' => 580,
                'width' => 160,
                'height' => 30,
            ],
        ])
        ->assertForbidden();
});

test('unsupported document type returns not found', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, ['settings.application.view']);

    $this->actingAs($user)
        ->getJson(route('application.esign-placement.show', 'salary_certificate'))
        ->assertNotFound();
});
