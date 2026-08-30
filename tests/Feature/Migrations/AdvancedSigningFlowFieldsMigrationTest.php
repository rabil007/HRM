<?php

use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentRecipientType;
use App\Enums\DocumentSigningFlowStatus;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentSigningFlow;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/document-workflow-fixtures.php';

function advancedSigningFlowFieldsMigration(): object
{
    return require database_path('migrations/2026_08_29_100006_add_advanced_signing_flow_fields.php');
}

function signingFlowLinkageMigration(): object
{
    return require database_path('migrations/2026_08_29_085833_add_signing_flow_linkage_to_document_recipient_requests.php');
}

/**
 * @return Collection<int, string>
 */
function recipientRequestIndexNames()
{
    return collect(Schema::getIndexes('document_recipient_requests'))->pluck('name');
}

function assertAdvancedSigningFlowFieldsSchema(): void
{
    $hasSigningFlowForeignKey = collect(Schema::getForeignKeys('document_recipient_requests'))
        ->contains(fn (array $foreignKey): bool => in_array('document_signing_flow_id', $foreignKey['columns'], true));

    expect(Schema::hasColumn('document_signing_preset_steps', 'step_label'))->toBeTrue()
        ->and(Schema::hasColumn('document_recipient_requests', 'signature_slot_key'))->toBeTrue()
        ->and(Schema::hasColumn('document_recipient_requests', 'signing_step_label_snapshot'))->toBeTrue()
        ->and(recipientRequestIndexNames()->contains('doc_rr_sign_flow_step_uq'))->toBeTrue()
        ->and(recipientRequestIndexNames()->contains('doc_rr_sign_flow_step_idx'))->toBeFalse()
        ->and($hasSigningFlowForeignKey)->toBeTrue();
}

test('advanced signing flow fields migration recovers from a partially applied up', function () {
    $migration = advancedSigningFlowFieldsMigration();

    assertAdvancedSigningFlowFieldsSchema();

    $migration->down();

    expect(Schema::hasColumn('document_signing_preset_steps', 'step_label'))->toBeFalse()
        ->and(Schema::hasColumn('document_recipient_requests', 'signature_slot_key'))->toBeFalse()
        ->and(Schema::hasColumn('document_recipient_requests', 'signing_step_label_snapshot'))->toBeFalse()
        ->and(recipientRequestIndexNames()->contains('doc_rr_sign_flow_step_idx'))->toBeTrue()
        ->and(recipientRequestIndexNames()->contains('doc_rr_sign_flow_step_uq'))->toBeFalse();

    // Simulate the known failed-DDL local state: preset step_label already exists.
    Schema::table('document_signing_preset_steps', function (Blueprint $table) {
        $table->string('step_label', 120)->nullable();
    });

    $migration->up();
    assertAdvancedSigningFlowFieldsSchema();

    // Idempotent re-run must remain safe.
    $migration->up();
    assertAdvancedSigningFlowFieldsSchema();
});

test('advanced signing flow fields migration down restores the non-unique index before dropping unique', function () {
    $migration = advancedSigningFlowFieldsMigration();

    assertAdvancedSigningFlowFieldsSchema();

    $migration->down();

    $hasSigningFlowForeignKey = collect(Schema::getForeignKeys('document_recipient_requests'))
        ->contains(fn (array $foreignKey): bool => in_array('document_signing_flow_id', $foreignKey['columns'], true));

    expect(recipientRequestIndexNames()->contains('doc_rr_sign_flow_step_idx'))->toBeTrue()
        ->and(recipientRequestIndexNames()->contains('doc_rr_sign_flow_step_uq'))->toBeFalse()
        ->and($hasSigningFlowForeignKey)->toBeTrue();

    $migration->up();
    assertAdvancedSigningFlowFieldsSchema();
});

test('signing flow linkage migration down drops the foreign key before its supporting indexes', function () {
    $fieldsMigration = advancedSigningFlowFieldsMigration();
    $linkageMigration = signingFlowLinkageMigration();

    assertAdvancedSigningFlowFieldsSchema();

    $fieldsMigration->down();

    expect(fn () => $linkageMigration->down())->not->toThrow(Throwable::class);

    $hasSigningFlowForeignKey = collect(Schema::getForeignKeys('document_recipient_requests'))
        ->contains(fn (array $foreignKey): bool => in_array('document_signing_flow_id', $foreignKey['columns'], true));

    expect(Schema::hasColumn('document_recipient_requests', 'document_signing_flow_id'))->toBeFalse()
        ->and(Schema::hasColumn('document_recipient_requests', 'signing_step_sequence'))->toBeFalse()
        ->and(recipientRequestIndexNames()->contains('doc_rr_sign_flow_step_idx'))->toBeFalse()
        ->and(recipientRequestIndexNames()->contains('doc_rr_comp_sign_flow_stat_idx'))->toBeFalse()
        ->and($hasSigningFlowForeignKey)->toBeFalse();

    $linkageMigration->up();
    $fieldsMigration->up();
    assertAdvancedSigningFlowFieldsSchema();
});

test('advanced signing flow fields migration fails clearly when flow-step duplicates exist', function () {
    $migration = advancedSigningFlowFieldsMigration();
    $migration->down();

    $fixtures = makeGeneratedDocumentWorkflowFixtures();
    $starter = User::factory()->create();

    $flow = DocumentSigningFlow::query()->create([
        'company_id' => $fixtures['company']->id,
        'document_instance_id' => $fixtures['instance']->id,
        'starting_document_instance_version_id' => $fixtures['version']->id,
        'preset_name_snapshot' => 'Duplicate step fixture',
        'routing_definition_snapshot' => [
            'schema_version' => 1,
            'steps' => [[
                'sequence' => 1,
                'recipient_role' => 'subject',
            ]],
        ],
        'status' => DocumentSigningFlowStatus::Active,
        'current_step_sequence' => 1,
        'started_by' => $starter->id,
        'started_at' => now(),
    ]);

    foreach ([1, 2] as $i) {
        DocumentRecipientRequest::query()->create([
            'company_id' => $fixtures['company']->id,
            'document_instance_id' => $fixtures['instance']->id,
            'source_document_instance_version_id' => $fixtures['version']->id,
            'document_signing_flow_id' => $flow->id,
            'signing_step_sequence' => 1,
            'action' => DocumentRecipientAction::Sign,
            'recipient_type' => DocumentRecipientType::SubjectEmployee,
            'recipient_role' => DocumentRecipientRole::Subject,
            'employee_id' => $fixtures['employee']->id,
            'recipient_name_snapshot' => $fixtures['employee']->name.' '.$i,
            'status' => DocumentRecipientRequestStatus::AwaitingAction,
            'token_hash' => hash('sha256', 'dup-step-'.$i.'-'.(string) Str::uuid()),
            'expires_at' => now()->addDays(14),
            'requested_at' => now(),
            'source_checksum_sha256' => $fixtures['version']->checksum,
        ]);
    }

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'Cannot create unique index doc_rr_sign_flow_step_uq');

    DocumentRecipientRequest::query()
        ->where('document_signing_flow_id', $flow->id)
        ->delete();

    $migration->up();
    assertAdvancedSigningFlowFieldsSchema();
});
