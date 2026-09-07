<?php

use App\Enums\CrewMobilisationReadinessStatus;
use App\Enums\CrewMovementAction;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Support\CrewMovements\CrewMobilisationReadinessResolver;
use App\Support\CrewMovements\CrewMovementAvailableActions;
use App\Support\CrewMovements\CrewMovementService;
use Carbon\Carbon;

function makePreMobilisationAssignment(): array
{
    $fixtures = makeCrewAssignmentFixtures();
    $assignment = app(CrewMovementService::class)->createDraft(
        $fixtures['company']->id,
        $fixtures['employee']->id,
        ['rank_id' => $fixtures['rank']->id],
        $fixtures['user']->id,
    )->load(['employee', 'currentPhase', 'company']);

    return [...$fixtures, 'assignment' => $assignment];
}

it('marks a ready employee when required documents are valid', function () {
    Carbon::setTestNow('2026-05-20');

    $fixtures = makePreMobilisationAssignment();
    $type = DocumentType::query()->create([
        'title' => 'Seaman Book '.uniqid(),
        'is_active' => true,
    ]);
    makeDocumentRequirement($fixtures['company']->id, $type->id, requiredForAll: true);
    EmployeeDocument::query()->create([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $fixtures['employee']->id,
        'document_type_id' => $type->id,
        'type' => 'other',
        'document_type' => (string) $type->id,
        'file_path' => 'employee-documents/test/seaman.pdf',
        'expiry_date' => '2027-01-01',
        'status' => 'valid',
    ]);

    $result = (new CrewMobilisationReadinessResolver)->forAssignment($fixtures['assignment']->fresh(['employee', 'currentPhase']));

    expect($result->applies)->toBeTrue()
        ->and($result->status)->toBe(CrewMobilisationReadinessStatus::Ready)
        ->and($result->checksTotal)->toBe(1)
        ->and($result->checksClear)->toBe(1)
        ->and($result->problems)->toBe([]);
});

it('marks missing required documents as not ready', function () {
    $fixtures = makePreMobilisationAssignment();
    $type = DocumentType::query()->create([
        'title' => 'Seaman Book '.uniqid(),
        'is_active' => true,
    ]);
    makeDocumentRequirement($fixtures['company']->id, $type->id, requiredForAll: true);

    $result = (new CrewMobilisationReadinessResolver)->forAssignment($fixtures['assignment']->fresh(['employee', 'currentPhase']));

    expect($result->status)->toBe(CrewMobilisationReadinessStatus::NotReady)
        ->and($result->problems[0]['code'])->toBe('document_missing');
});

it('marks expired required documents as not ready', function () {
    Carbon::setTestNow('2026-05-20');

    $fixtures = makePreMobilisationAssignment();
    $type = DocumentType::query()->create([
        'title' => 'Medical '.uniqid(),
        'is_active' => true,
    ]);
    makeDocumentRequirement($fixtures['company']->id, $type->id, requiredForAll: true);
    EmployeeDocument::query()->create([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $fixtures['employee']->id,
        'document_type_id' => $type->id,
        'type' => 'other',
        'document_type' => (string) $type->id,
        'file_path' => 'employee-documents/test/medical.pdf',
        'expiry_date' => '2026-05-01',
        'status' => 'expired',
    ]);

    $result = (new CrewMobilisationReadinessResolver)->forAssignment($fixtures['assignment']->fresh(['employee', 'currentPhase']));

    expect($result->status)->toBe(CrewMobilisationReadinessStatus::NotReady)
        ->and($result->problems[0]['code'])->toBe('document_expired');
});

it('marks expiring required documents as attention', function () {
    Carbon::setTestNow('2026-05-20');

    $fixtures = makePreMobilisationAssignment();
    $type = DocumentType::query()->create([
        'title' => 'BOSIET '.uniqid(),
        'is_active' => true,
    ]);
    makeDocumentRequirement($fixtures['company']->id, $type->id, requiredForAll: true);
    EmployeeDocument::query()->create([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $fixtures['employee']->id,
        'document_type_id' => $type->id,
        'type' => 'other',
        'document_type' => (string) $type->id,
        'file_path' => 'employee-documents/test/bosiet.pdf',
        'expiry_date' => '2026-06-07',
        'status' => 'expiring_soon',
    ]);

    $result = (new CrewMobilisationReadinessResolver)->forAssignment($fixtures['assignment']->fresh(['employee', 'currentPhase']));

    expect($result->status)->toBe(CrewMobilisationReadinessStatus::Attention)
        ->and($result->problems[0]['code'])->toBe('document_expiring');
});

it('treats a required document with no upload as missing', function () {
    $fixtures = makePreMobilisationAssignment();
    $type = DocumentType::query()->create([
        'title' => 'Passport '.uniqid(),
        'is_active' => true,
    ]);
    makeDocumentRequirement($fixtures['company']->id, $type->id, requiredForAll: true);

    $result = (new CrewMobilisationReadinessResolver)->forAssignment($fixtures['assignment']->fresh(['employee', 'currentPhase']));

    expect($result->checksTotal)->toBe(1)
        ->and($result->checksClear)->toBe(0)
        ->and($result->problems[0]['code'])->toBe('document_missing');
});

it('does not apply other company document requirements', function () {
    $fixtures = makePreMobilisationAssignment();
    $foreign = makeCrewAssignmentFixtures();
    $type = DocumentType::query()->create([
        'title' => 'Foreign Seaman Book '.uniqid(),
        'is_active' => true,
    ]);
    makeDocumentRequirement($foreign['company']->id, $type->id, requiredForAll: true);

    $result = (new CrewMobilisationReadinessResolver)->forAssignment($fixtures['assignment']->fresh(['employee', 'currentPhase']));

    expect($result->status)->toBe(CrewMobilisationReadinessStatus::Ready)
        ->and($result->checksTotal)->toBe(0);
});

it('does not treat readiness as a movement restriction', function () {
    $fixtures = makePreMobilisationAssignment();
    $type = DocumentType::query()->create([
        'title' => 'Seaman Book '.uniqid(),
        'is_active' => true,
    ]);
    makeDocumentRequirement($fixtures['company']->id, $type->id, requiredForAll: true);

    $result = (new CrewMobilisationReadinessResolver)->forAssignment($fixtures['assignment']->fresh(['employee', 'currentPhase']));

    expect($result->status)->toBe(CrewMobilisationReadinessStatus::NotReady)
        ->and($fixtures['assignment']->availableActions ?? CrewMovementAvailableActions::for($fixtures['assignment']))
        ->toContain(CrewMovementAction::ApproveMobilisation->value);
});
