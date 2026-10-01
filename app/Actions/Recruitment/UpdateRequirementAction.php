<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementAttachment;
use App\Models\User;
use App\Support\Recruitment\RequirementAttachmentStorage;
use App\Support\Recruitment\SendRequirementLifecycleEmails;
use App\Support\Recruitment\SyncRequirementNotificationRecipients;
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
        $reassignedRecruiterId = null;

        try {
            $result = DB::transaction(function () use ($requirement, $userId, $data, $attachment, &$storedFilePath, &$reassignedRecruiterId): RecruitmentRequirement {
                /** @var RecruitmentRequirement $locked */
                $locked = RecruitmentRequirement::query()
                    ->where('id', $requirement->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $companyId = (int) $locked->company_id;
                $previousAssignedTo = $locked->assigned_to !== null ? (int) $locked->assigned_to : null;

                if ($locked->status->isEditable()) {
                    $locked->update([
                        'client_id' => $data['client_id'],
                        'project_id' => $data['project_id'] ?? null,
                        'request_received_date' => $data['request_received_date'],
                        'location' => $data['location'] ?? null,
                        'priority' => $data['priority'],
                        'assigned_to' => $data['assigned_to'] ?? null,
                        'notes' => $data['notes'] ?? null,
                        'updated_by' => $userId,
                    ]);
                } elseif ($locked->status->allowsPendingReassignment()) {
                    $newAssignedTo = array_key_exists('assigned_to', $data)
                        ? ($data['assigned_to'] !== null ? (int) $data['assigned_to'] : null)
                        : $previousAssignedTo;

                    $locked->update([
                        'assigned_to' => $newAssignedTo,
                        'updated_by' => $userId,
                    ]);
                } else {
                    throw ValidationException::withMessages([
                        'status' => "Cannot edit a {$locked->status->label()} requirement.",
                    ]);
                }

                if (array_key_exists('notification_recipient_ids', $data)) {
                    SyncRequirementNotificationRecipients::sync(
                        $locked,
                        is_array($data['notification_recipient_ids']) ? $data['notification_recipient_ids'] : [],
                    );
                }

                $newAssignedTo = $locked->assigned_to !== null ? (int) $locked->assigned_to : null;
                if (
                    $locked->status === RequirementStatus::PendingApproval
                    && $newAssignedTo !== null
                    && $newAssignedTo !== $previousAssignedTo
                ) {
                    $reassignedRecruiterId = $newAssignedTo;
                }

                if ($attachment instanceof UploadedFile) {
                    if (! $locked->status->isEditable()) {
                        throw ValidationException::withMessages([
                            'attachment' => 'Attachments can only be uploaded while the requirement is editable.',
                        ]);
                    }

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

                return $locked->load(['lines.position', 'client', 'project', 'attachments', 'notificationRecipients.user', 'assignedRecruiter', 'creator', 'company']);
            });
        } catch (Throwable $exception) {
            if ($storedFilePath !== null) {
                Storage::disk(RequirementAttachmentStorage::DISK)->delete($storedFilePath);
            }

            throw $exception;
        }

        if ($reassignedRecruiterId !== null) {
            $recruiterId = $reassignedRecruiterId;
            DB::afterCommit(function () use ($result, $recruiterId): void {
                $recruiter = User::query()->find($recruiterId);
                if ($recruiter !== null) {
                    SendRequirementLifecycleEmails::pendingReassigned($result, $recruiter);
                }
            });
        }

        return $result;
    }
}
