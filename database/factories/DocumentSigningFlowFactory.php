<?php

namespace Database\Factories;

use App\Enums\DocumentSigningFlowStatus;
use App\Models\Company;
use App\Models\DocumentInstance;
use App\Models\DocumentInstanceVersion;
use App\Models\DocumentSigningFlow;
use App\Models\DocumentSigningPreset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentSigningFlow>
 */
class DocumentSigningFlowFactory extends Factory
{
    protected $model = DocumentSigningFlow::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'document_instance_id' => DocumentInstance::factory(),
            'document_signing_preset_id' => DocumentSigningPreset::factory(),
            'starting_document_instance_version_id' => DocumentInstanceVersion::factory(),
            'preset_name_snapshot' => fake()->words(3, true),
            'routing_definition_snapshot' => [
                'schema_version' => 1,
                'steps' => [
                    [
                        'sequence' => 1,
                        'recipient_role' => 'subject',
                        'target_type' => 'subject_employee',
                        'recipient_user_id' => null,
                        'recipient_name' => 'Employee',
                    ],
                ],
            ],
            'status' => DocumentSigningFlowStatus::Active,
            'current_step_sequence' => 1,
            'started_by' => User::factory(),
            'started_at' => now(),
        ];
    }
}
