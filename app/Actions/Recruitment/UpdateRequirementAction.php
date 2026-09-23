<?php

namespace App\Actions\Recruitment;

use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementAttachment;
use App\Support\Recruitment\RequirementAttachmentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

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
        $storedFilePath = null;

        try {
            return DB::transaction(function () use ($requirement, $userId, $data, $attachment, &$storedFilePath): RecruitmentRequirement {
                /** @var RecruitmentRequirement $locked */
                $locked = RecruitmentRequirement::query()
                    ->where('id', $requirement->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $locked->status->isEditable()) {
                    throw ValidationException::withMessages([
                        'status' => "Cannot edit a {$locked->status->label()} requirement.",
                    ]);
                }

                $companyId = (int) $locked->company_id;

                $locked->update([
                    'client_id' => $data['client_id'],
                    'project_id' => $data['project_id'] ?? null,
                    'client_reference_number' => $data['client_reference_number'] ?? null,
                    'request_received_date' => $data['request_received_date'],
                    'location' => $data['location'] ?? null,
                    'priority' => $data['priority'],
                    'assigned_to' => $data['assigned_to'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'updated_by' => $userId,
                ]);

                if ($attachment instanceof UploadedFile) {
                    $storage = new RequirementAttachmentStorage;
                    $fileMeta = $storage->store($locked, $attachment);
                    $storedFilePath = $fileMeta['file_path'];

                    RecruitmentRequirementAttachment::create([
                        'company_id' => $companyId,
                        'recruitment_requirement_id' => $locked->id,
                        'file_path' => $fileMeta['file_path'],
                        'original_file_name' => $fileMeta['original_file_name'],
                        'mime_type' => $fileMeta['mime_type'],
                        'file_size_bytes' => $fileMeta['file_size_bytes'],
                        'file_checksum' => $fileMeta['file_checksum'],
                        'uploaded_by' => $userId,
                    ]);

                    activity('recruitment')
                        ->causedBy($userId)
                        ->performedOn($locked)
                        ->withProperties([
                            'company_id' => $companyId,
                            'file_name' => $fileMeta['original_file_name'],
                        ])
                        ->log('Original client request attachment uploaded.');
                }

                activity('recruitment')
                    ->causedBy($userId)
                    ->performedOn($locked)
                    ->withProperties([
                        'company_id' => $companyId,
                        'requirement_number' => $locked->requirement_number,
                    ])
                    ->log("Requirement {$locked->requirement_number} updated.");

                return $locked->load(['lines.position', 'client', 'project', 'attachments']);
            });
        } catch (Throwable $exception) {
            if ($storedFilePath !== null) {
                Storage::disk(RequirementAttachmentStorage::DISK)->delete($storedFilePath);
            }

            throw $exception;
        }
    }
}
