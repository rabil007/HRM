<?php

namespace App\Support\EmployeeDocuments\Actions;

use App\Models\DocumentRequirement;
use App\Models\DocumentType;
use App\Models\User;
use App\Support\EmployeeDocuments\DocumentRequirementSummary;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

final class SyncDocumentRequirement
{
    /**
     * @param  array{
     *     is_required: bool,
     *     required_for_all?: bool,
     *     department_ids?: list<int|string>,
     *     position_ids?: list<int|string>,
     *     rank_ids?: list<int|string>,
     *     project_ids?: list<int|string>,
     *     require_issue_date?: bool,
     *     require_expiry_date?: bool,
     *     require_document_number?: bool
     * }  $data
     */
    public function handle(int $companyId, DocumentType $documentType, array $data, ?User $user = null): ?DocumentRequirement
    {
        return DB::transaction(function () use ($companyId, $documentType, $data, $user): ?DocumentRequirement {
            $requirement = DocumentRequirement::query()
                ->forCompany($companyId)
                ->where('document_type_id', $documentType->id)
                ->with(['departments:id,name', 'positions:id,title', 'ranks:id,name', 'projects:id,title', 'documentType:id,title'])
                ->first();

            $previousPhrase = DocumentRequirementSummary::auditPhrase($requirement);
            $previousMetadata = $this->metadataSnapshot($requirement);

            $isRequired = (bool) ($data['is_required'] ?? false);
            $requiredForAll = (bool) ($data['required_for_all'] ?? false);
            $departmentIds = $this->integerIds($data['department_ids'] ?? []);
            $positionIds = $this->integerIds($data['position_ids'] ?? []);
            $rankIds = $this->integerIds($data['rank_ids'] ?? []);
            $projectIds = $this->integerIds($data['project_ids'] ?? []);

            if (! $isRequired && $requirement === null) {
                return null;
            }

            if ($requirement === null) {
                $requirement = new DocumentRequirement([
                    'company_id' => $companyId,
                    'document_type_id' => $documentType->id,
                    'created_by' => $user?->id,
                ]);
            }

            if ($isRequired) {
                $requirement->fill([
                    'required_for_all' => $requiredForAll,
                    'require_issue_date' => (bool) ($data['require_issue_date'] ?? false),
                    'require_expiry_date' => (bool) ($data['require_expiry_date'] ?? false),
                    'require_document_number' => (bool) ($data['require_document_number'] ?? false),
                    'is_active' => true,
                    'updated_by' => $user?->id,
                ]);
            } else {
                $requirement->fill([
                    'is_active' => false,
                    'updated_by' => $user?->id,
                ]);
            }
            $requirement->disableLogging();
            $requirement->save();

            if ($isRequired) {
                $requirement->departments()->sync($departmentIds);
                $requirement->positions()->sync($positionIds);
                $requirement->ranks()->sync($rankIds);
                $requirement->projects()->sync($projectIds);
            }

            $requirement->unsetRelation('departments');
            $requirement->unsetRelation('positions');
            $requirement->unsetRelation('ranks');
            $requirement->unsetRelation('projects');
            $requirement->load(['departments:id,name', 'positions:id,title', 'ranks:id,name', 'projects:id,title', 'documentType:id,title']);

            $nextPhrase = DocumentRequirementSummary::auditPhrase($requirement);
            $nextMetadata = $this->metadataSnapshot($requirement);

            if ($previousPhrase !== $nextPhrase || $previousMetadata !== $nextMetadata) {
                $description = $previousPhrase !== $nextPhrase
                    ? sprintf('%s: %s → %s', $documentType->title, $previousPhrase, $nextPhrase)
                    : sprintf('%s: required information updated', $documentType->title);

                activity()
                    ->performedOn($requirement)
                    ->causedBy($user)
                    ->event('updated')
                    ->withProperties([
                        'company_id' => $companyId,
                        'document_type' => $documentType->title,
                        'old' => $previousPhrase,
                        'attributes' => $nextPhrase,
                        'old_required_information' => $previousMetadata,
                        'required_information' => $nextMetadata,
                    ])
                    ->tap(function (Activity $activity) use ($companyId): void {
                        $activity->company_id = $companyId;
                    })
                    ->log($description);
            }

            return $requirement;
        });
    }

    /**
     * @return array{require_issue_date: bool, require_expiry_date: bool, require_document_number: bool}
     */
    private function metadataSnapshot(?DocumentRequirement $requirement): array
    {
        return [
            'require_issue_date' => (bool) ($requirement?->require_issue_date ?? false),
            'require_expiry_date' => (bool) ($requirement?->require_expiry_date ?? false),
            'require_document_number' => (bool) ($requirement?->require_document_number ?? false),
        ];
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    private function integerIds(array $ids): array
    {
        return collect($ids)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
