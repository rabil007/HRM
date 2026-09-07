<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMobilisationReadinessStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\CrewMovements\CrewAssignmentPresenter;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewMovements\CurrentCrewQuery;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

it('shows mobilisation readiness on the assignment show page', function () {
    $fixtures = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
        'crew_operations.movements.perform',
        'documents.view',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    $type = DocumentType::query()->create([
        'title' => 'Seaman Book '.uniqid(),
        'is_active' => true,
    ]);
    makeDocumentRequirement($fixtures['company']->id, $type->id, requiredForAll: true);

    $assignment = app(CrewMovementService::class)->createDraft(
        $fixtures['company']->id,
        $fixtures['employee']->id,
        ['rank_id' => $fixtures['rank']->id],
        $fixtures['user']->id,
    );

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-assignments.show', $assignment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/show')
            ->where('assignment.mobilisation_readiness.status', CrewMobilisationReadinessStatus::NotReady->value)
            ->where('assignment.recommended_action.type', 'readiness')
            ->where('assignment.available_actions.0', CrewMovementAction::ApproveMobilisation->value)
        );
});

it('does not block mobilisation when readiness is not ready', function () {
    $fixtures = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
        'crew_operations.movements.perform',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    $type = DocumentType::query()->create([
        'title' => 'Seaman Book '.uniqid(),
        'is_active' => true,
    ]);
    makeDocumentRequirement($fixtures['company']->id, $type->id, requiredForAll: true);

    $assignment = app(CrewMovementService::class)->createDraft(
        $fixtures['company']->id,
        $fixtures['employee']->id,
        ['rank_id' => $fixtures['rank']->id],
        $fixtures['user']->id,
    );

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::ApproveMobilisation->value,
            'occurred_at' => '2026-01-01 08:00:00',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $assignment->refresh()->load('currentPhase');

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::TravelIn);
});

it('omits document links without documents.view', function () {
    $fixtures = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
        'crew_operations.movements.perform',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    $assignment = app(CrewMovementService::class)->createDraft(
        $fixtures['company']->id,
        $fixtures['employee']->id,
        ['rank_id' => $fixtures['rank']->id],
        $fixtures['user']->id,
    );

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-assignments.show', $assignment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('assignment.mobilisation_readiness.documents_href', null)
            ->where('can.view_documents', false)
        );
});

it('does not leak another company employee documents into readiness', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $foreign = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    $type = DocumentType::query()->create([
        'title' => 'Foreign Medical '.uniqid(),
        'is_active' => true,
    ]);
    makeDocumentRequirement($foreign['company']->id, $type->id, requiredForAll: true);
    EmployeeDocument::query()->create([
        'company_id' => $foreign['company']->id,
        'employee_id' => $foreign['employee']->id,
        'document_type_id' => $type->id,
        'type' => 'other',
        'document_type' => (string) $type->id,
        'file_path' => 'employee-documents/test/foreign.pdf',
        'expiry_date' => '2020-01-01',
        'status' => 'expired',
    ]);

    $assignment = app(CrewMovementService::class)->createDraft(
        $fixtures['company']->id,
        $fixtures['employee']->id,
        ['rank_id' => $fixtures['rank']->id],
        $fixtures['user']->id,
    );

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-assignments.show', $assignment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('assignment.mobilisation_readiness.status', CrewMobilisationReadinessStatus::Ready->value)
            ->where('assignment.mobilisation_readiness.checks_total', 0)
        );
});

it('batches mobilisation readiness on the crew assignment index', function () {
    $fixtures = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    $type = DocumentType::query()->create([
        'title' => 'Seaman Book '.uniqid(),
        'is_active' => true,
    ]);
    makeDocumentRequirement($fixtures['company']->id, $type->id, requiredForAll: true);

    for ($i = 0; $i < 6; $i++) {
        $employee = $i === 0
            ? $fixtures['employee']
            : Employee::factory()->forCompany($fixtures['company'])->create([
                'rank_id' => $fixtures['rank']->id,
                'status' => 'active',
            ]);

        app(CrewMovementService::class)->createDraft(
            $fixtures['company']->id,
            $employee->id,
            ['rank_id' => $fixtures['rank']->id],
            $fixtures['user']->id,
        );
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $page = CurrentCrewQuery::paginate((int) $fixtures['company']->id);
    collect($page->items())->each(fn ($assignment) => CrewAssignmentPresenter::listItem($assignment));
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($page->total())->toBe(6)
        ->and($queryCount)->toBeLessThan(18);
});
