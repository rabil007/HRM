<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementAttachment;
use App\Models\RecruitmentRequirementLine;
use App\Support\Recruitment\GenerateRequirementNumber;
use App\Support\Recruitment\RequirementAttachmentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class CreateRequirementAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(
        int $companyId,
        int $userId,
        array $data,
        ?UploadedFile $attachment = null,
    ): RecruitmentRequirement {
        $storedFilePath = null;

        try {
            return DB::transaction(function () use ($companyId, $userId, $data, $attachment, &$storedFilePath): RecruitmentRequirement {
                $requirementNumber = GenerateRequirementNumber::next($companyId);
                $asOpen = (bool) ($data['as_open'] ?? false);
                $status = $asOpen ? RequirementStatus::Open : RequirementStatus::Draft;

                $requirement = RecruitmentRequirement::create([
                    'company_id' => $companyId,
                    'requirement_number' => $requirementNumber,
                    'client_id' => $data['client_id'],
                    'project_id' => $data['project_id'] ?? null,
                    'client_reference_number' => $data['client_reference_number'] ?? null,
                    'request_received_date' => $data['request_received_date'],
                    'required_by_date' => $data['required_by_date'],
                    'location' => $data['location'] ?? null,
                    'priority' => $data['priority'],
                    'assigned_to' => $data['assigned_to'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'status' => $status,
                    'repeated_from_id' => $data['repeated_from_id'] ?? null,
                    'opened_at' => $asOpen ? now() : null,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);

                foreach ($data['lines'] as $lineData) {
                    RecruitmentRequirementLine::create([
                        'company_id' => $companyId,
                        'recruitment_requirement_id' => $requirement->id,
                        'position_id' => $lineData['position_id'],
                        'required_headcount' => $lineData['required_headcount'],
                        'line_notes' => $lineData['line_notes'] ?? null,
                        'status' => RequirementLineStatus::Open,
                    ]);
                }

                if ($attachment instanceof UploadedFile) {
                    $storage = new RequirementAttachmentStorage;
                    $fileMeta = $storage->store($requirement, $attachment);
                    $storedFilePath = $fileMeta['file_path'];

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
                        'requirement_number' => $requirementNumber,
                        'status' => $status->value,
                    ])
                    ->log("Requirement {$requirementNumber} created as {$status->label()}.");

                return $requirement->load(['lines.position', 'client', 'project', 'attachments']);
            });
        } catch (Throwable $exception) {
            if ($storedFilePath !== null) {
                Storage::disk(RequirementAttachmentStorage::DISK)->delete($storedFilePath);
            }

            throw $exception;
        }
    }
}
