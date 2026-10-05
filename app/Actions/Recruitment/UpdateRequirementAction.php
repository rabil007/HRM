<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementAttachment;
use App\Models\RecruitmentRequirementLine;
use App\Models\RecruitmentRequirementStatusTransition;
use App\Support\Recruitment\RecordRequirementStatusTransition;
use App\Support\Recruitment\RecruiterOptionsQuery;
use App\Support\Recruitment\RequirementAttachmentStorage;
use App\Support\Recruitment\SendRequirementLifecycleEmails;
use App\Support\Recruitment\SyncRequirementNotificationRecipients;
use App\Support\Settings\CompanyCurrency;
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
        $shouldNotifyReassignment = false;
        /** @var RecruitmentRequirementStatusTransition|null $reassignmentTransition */
        $reassignmentTransition = null;

        try {
            $result = DB::transaction(function () use ($requirement, $userId, $data, $attachment, &$storedFilePath, &$shouldNotifyReassignment, &$reassignmentTransition): RecruitmentRequirement {
                /** @var RecruitmentRequirement $locked */
                $locked = RecruitmentRequirement::query()
                    ->where('id', $requirement->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $companyId = (int) $locked->company_id;
                $previousAssignedTo = $locked->assigned_to !== null ? (int) $locked->assigned_to : null;

                if ($locked->status->isEditable()) {
                    $assignedTo = array_key_exists('assigned_to', $data)
                        ? ($data['assigned_to'] !== null ? (int) $data['assigned_to'] : null)
                        : $previousAssignedTo;

                    RecruiterOptionsQuery::assertEligibleApprover($assignedTo, $companyId, required: false);

                    if (
                        $assignedTo !== null
                        && $locked->created_by !== null
                        && (int) $locked->created_by === $assignedTo
                    ) {
                        throw ValidationException::withMessages([
                            'assigned_to' => 'The requester cannot also be the assigned recruiter. Self-approval is not allowed.',
                        ]);
                    }

                    $locked->update([
                        'client_id' => $data['client_id'],
                        'project_id' => $data['project_id'] ?? null,
                        'request_received_date' => $data['request_received_date'],
                        'location' => $data['location'] ?? null,
                        'priority' => $data['priority'],
                        'assigned_to' => $assignedTo,
                        'notes' => $data['notes'] ?? null,
                        'updated_by' => $userId,
                    ]);

                    if (array_key_exists('lines', $data) && is_array($data['lines'])) {
                        $companyCurrency = CompanyCurrency::codeForCompany($companyId);
                        $existingLines = $locked->lines()->lockForUpdate()->get();

                        foreach ($data['lines'] as $lineInput) {
                            if (! is_array($lineInput)) {
                                continue;
                            }

                            /** @var RecruitmentRequirementLine|null $targetLine */
                            $targetLine = null;
                            if (! empty($lineInput['id'])) {
                                $targetLine = $existingLines->firstWhere('id', (int) $lineInput['id']);
                            } elseif (! empty($lineInput['position_id'])) {
                                $targetLine = $existingLines->firstWhere('position_id', (int) $lineInput['position_id']);
                            }

                            if ($targetLine !== null) {
                                $updates = [];
                                if (array_key_exists('salary_min', $lineInput)) {
                                    $updates['salary_min'] = $lineInput['salary_min'] !== null && $lineInput['salary_min'] !== '' ? $lineInput['salary_min'] : null;
                                }
                                if (array_key_exists('salary_max', $lineInput)) {
                                    $updates['salary_max'] = $lineInput['salary_max'] !== null && $lineInput['salary_max'] !== '' ? $lineInput['salary_max'] : null;
                                }
                                if (array_key_exists('salary_min', $updates) || array_key_exists('salary_max', $updates)) {
                                    $updates['salary_currency_code'] = $companyCurrency;
                                }
                                if (array_key_exists('line_notes', $lineInput)) {
                                    $updates['line_notes'] = $lineInput['line_notes'];
                                }

                                if (! empty($updates)) {
                                    $targetLine->update($updates);
                                }
                            }
                        }
                    }
                } elseif ($locked->status->allowsPendingReassignment()) {
                    if (! array_key_exists('assigned_to', $data) || $data['assigned_to'] === null || $data['assigned_to'] === '') {
                        throw ValidationException::withMessages([
                            'assigned_to' => 'An assigned recruiter is required while the requirement is pending approval.',
                        ]);
                    }

                    $newAssignedTo = (int) $data['assigned_to'];

                    RecruiterOptionsQuery::assertEligibleApprover($newAssignedTo, $companyId, required: true);

                    if ($locked->created_by !== null && (int) $locked->created_by === $newAssignedTo) {
                        throw ValidationException::withMessages([
                            'assigned_to' => 'The requester cannot also be the assigned recruiter. Self-approval is not allowed.',
                        ]);
                    }

                    $locked->update([
                        'assigned_to' => $newAssignedTo,
                        'updated_by' => $userId,
                    ]);

                    if ($newAssignedTo !== $previousAssignedTo) {
                        $reassignmentTransition = RecordRequirementStatusTransition::handle(
                            $locked,
                            RequirementStatus::PendingApproval,
                            RequirementStatus::PendingApproval,
                            $userId,
                            'Recruiter reassigned',
                        );
                        $shouldNotifyReassignment = true;
                    }
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

                return $locked->load([
                    'lines.position',
                    'client',
                    'project',
                    'attachments',
                    'notificationRecipients.user',
                    'assignedRecruiter',
                    'creator',
                    'submitter',
                    'company',
                ]);
            });
        } catch (Throwable $exception) {
            if ($storedFilePath !== null) {
                Storage::disk(RequirementAttachmentStorage::DISK)->delete($storedFilePath);
            }

            throw $exception;
        }

        if ($shouldNotifyReassignment && $reassignmentTransition instanceof RecruitmentRequirementStatusTransition) {
            SendRequirementLifecycleEmails::pendingReassigned($result, $reassignmentTransition);
        }

        return $result;
    }
}
