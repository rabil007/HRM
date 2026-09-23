<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementLineStatus;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementAttachment;
use App\Models\RecruitmentRequirementLine;
use App\Support\Recruitment\RequirementAttachmentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateRequirementAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(
        RecruitmentRequirement $requirement,
        int $userId,
        array $data,
        ?UploadedFile $attachment = null,
    ): RecruitmentRequirement {
        if (! $requirement->status->isEditable()) {
            throw ValidationException::withMessages([
                'status' => "Cannot edit a {$requirement->status->label()} requirement.",
            ]);
        }

        return DB::transaction(function () use ($requirement, $userId, $data, $attachment): RecruitmentRequirement {
            $companyId = (int) $requirement->company_id;

            $requirement->update([
                'client_id' => $data['client_id'],
                'project_id' => $data['project_id'] ?? null,
                'client_reference_number' => $data['client_reference_number'] ?? null,
                'request_received_date' => $data['request_received_date'],
                'required_by_date' => $data['required_by_date'],
                'location' => $data['location'] ?? null,
                'priority' => $data['priority'],
                'assigned_to' => $data['assigned_to'] ?? null,
                'notes' => $data['notes'] ?? null,
                'updated_by' => $userId,
            ]);

            // Sync lines: match by position_id or line id
            $submittedLineIds = [];
            foreach ($data['lines'] as $lineInput) {
                /** @var RecruitmentRequirementLine|null $line */
                $line = null;
                if (! empty($lineInput['id'])) {
                    $line = $requirement->lines()->find($lineInput['id']);
                }

                if ($line === null) {
                    $line = $requirement->lines()->where('position_id', $lineInput['position_id'])->first();
                }

                if ($line !== null) {
                    $line->update([
                        'position_id' => $lineInput['position_id'],
                        'required_headcount' => $lineInput['required_headcount'],
                        'line_notes' => $lineInput['line_notes'] ?? null,
                    ]);
                    $submittedLineIds[] = $line->id;
                } else {
                    $newLine = RecruitmentRequirementLine::create([
                        'company_id' => $companyId,
                        'recruitment_requirement_id' => $requirement->id,
                        'position_id' => $lineInput['position_id'],
                        'required_headcount' => $lineInput['required_headcount'],
                        'line_notes' => $lineInput['line_notes'] ?? null,
                        'status' => RequirementLineStatus::Open,
                    ]);
                    $submittedLineIds[] = $newLine->id;
                }
            }

            // Remove lines that were deleted in the UI
            $requirement->lines()->whereNotIn('id', $submittedLineIds)->delete();

            if ($attachment instanceof UploadedFile) {
                $storage = new RequirementAttachmentStorage;
                $fileMeta = $storage->store($requirement, $attachment);

                RecruitmentRequirementAttachment::create([
                    'company_id' => $companyId,
                    'recruitment_requirement_id' => $requirement->id,
                    'file_path' => $fileMeta['file_path'],
                    'original_file_name' => $fileMeta['original_file_name'],
                    'mime_type' => $fileMeta['mime_type'],
                    'file_size_bytes' => $fileMeta['file_size_bytes'],
                    'file_checksum' => $fileMeta['file_checksum'],
                    'uploaded_by' => $userId,
                ]);

                activity('recruitment')
                    ->causedBy($userId)
                    ->performedOn($requirement)
                    ->withProperties([
                        'company_id' => $companyId,
                        'file_name' => $fileMeta['original_file_name'],
                    ])
                    ->log('Original client request attachment uploaded.');
            }

            activity('recruitment')
                ->causedBy($userId)
                ->performedOn($requirement)
                ->withProperties([
                    'company_id' => $companyId,
                    'requirement_number' => $requirement->requirement_number,
                ])
                ->log("Requirement {$requirement->requirement_number} updated.");

            return $requirement->load(['lines.position', 'client', 'project', 'attachments']);
        });
    }
}
