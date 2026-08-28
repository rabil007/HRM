<?php

namespace App\Support\Documents\Workflow\Actions;

use App\Enums\DocumentWorkflowPresetStatus;
use App\Models\DocumentWorkflowPreset;
use App\Models\DocumentWorkflowRequest;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\Documents\Workflow\ResolveDocumentWorkflowPreset;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateDocumentWorkflowRequestFromPreset
{
    public function __construct(
        private readonly ResolveDocumentWorkflowPreset $resolvePreset = new ResolveDocumentWorkflowPreset,
        private readonly CreateDocumentWorkflowRequest $createRequest = new CreateDocumentWorkflowRequest,
    ) {}

    public function handle(
        User $requester,
        int $companyId,
        EmployeeDocument $document,
        int $presetId,
        Employee $subjectEmployee,
    ): DocumentWorkflowRequest {
        return DB::transaction(function () use ($requester, $companyId, $document, $presetId, $subjectEmployee) {
            $preset = DocumentWorkflowPreset::query()
                ->forCompany($companyId)
                ->whereKey($presetId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($preset->status !== DocumentWorkflowPresetStatus::Active) {
                throw ValidationException::withMessages([
                    'workflow_preset_id' => ['The selected workflow preset is not active.'],
                ]);
            }

            $resolved = $this->resolvePreset->handle(
                preset: $preset,
                subjectEmployee: $subjectEmployee,
                requester: $requester,
                companyId: $companyId,
            );

            return $this->createRequest->handle(
                requester: $requester,
                companyId: $companyId,
                document: $document,
                stages: $resolved['stages'],
                presetProvenance: [
                    'preset_id' => $resolved['preset_id'],
                    'preset_name' => $resolved['preset_name'],
                    'routing_snapshot' => $resolved['routing_snapshot'],
                ],
            );
        });
    }
}
