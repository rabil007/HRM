<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementAttachment;
use App\Models\RecruitmentRequirementLine;
use App\Models\RecruitmentRequirementStatusTransition;
use App\Models\User;
use App\Support\Recruitment\RecordRequirementStatusTransition;
use App\Support\Recruitment\RecruiterOptionsQuery;
use App\Support\Recruitment\RequirementAttachmentStorage;
use App\Support\Recruitment\RequirementWorkflowAuthorization;
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
     * @param  string|null  $newStoredFilePath  Populated with a newly stored attachment path so callers
     *                                          that nest this action in a larger transaction can clean up
     *                                          orphan files if the outer operation fails after success here.
     */
    public function execute(
        RecruitmentRequirement $requirement,
        int $userId,
        array $data,
        ?UploadedFile $attachment = null,
        ?string &$newStoredFilePath = null,
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

                $actor = User::query()->findOrFail($userId);
                $companyId = (int) $locked->company_id;
                $previousAssignedTo = $locked->assigned_to !== null ? (int) $locked->assigned_to : null;

                if ($locked->status->isEditable()) {
                    RequirementWorkflowAuthorization::assertCanPrepare($actor, $locked);
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

                    $updates = [
                        'client_id' => array_key_exists('client_id', $data)
                            ? (filled($data['client_id']) ? (int) $data['client_id'] : null)
                            : $locked->client_id,
                        'project_id' => $data['project_id'] ?? null,
                        'request_received_date' => array_key_exists('request_received_date', $data)
                            ? (filled($data['request_received_date']) ? $data['request_received_date'] : null)
                            : $locked->request_received_date,
                        'location' => $data['location'] ?? null,
                        'priority' => $data['priority'] ?? $locked->priority,
                        'assigned_to' => $assignedTo,
                        'notes' => $data['notes'] ?? null,
                        'updated_by' => $userId,
                    ];

                    if (array_key_exists('required_by_date', $data)) {
                        $updates['required_by_date'] = filled($data['required_by_date'])
                            ? $data['required_by_date']
                            : null;
                    }

                    $locked->update($updates);

                    if (array_key_exists('lines', $data) && is_array($data['lines'])) {
                        $companyCurrency = CompanyCurrency::codeForCompany($companyId);
                        $existingLines = $locked->lines()->lockForUpdate()->get();

                        foreach ($data['lines'] as $lineInput) {
                            if (! is_array($lineInput) || ! filled($lineInput['position_id'] ?? null)) {
                                continue;
                            }

                            /** @var RecruitmentRequirementLine|null $targetLine */
                            $targetLine = null;
                            if (! empty($lineInput['id'])) {
                                $targetLine = $existingLines->firstWhere('id', (int) $lineInput['id']);
                            }

                            if ($targetLine === null) {
                                $targetLine = $existingLines->firstWhere('position_id', (int) $lineInput['position_id']);
                            }

                            $hasSalary = (isset($lineInput['salary_min']) && $lineInput['salary_min'] !== null && $lineInput['salary_min'] !== '')
                                || (isset($lineInput['salary_max']) && $lineInput['salary_max'] !== null && $lineInput['salary_max'] !== '');

                            if ($targetLine !== null) {
                                $updates = [];
                                if (array_key_exists('required_headcount', $lineInput) && filled($lineInput['required_headcount'])) {
                                    $updates['required_headcount'] = (int) $lineInput['required_headcount'];
                                }
                                if (array_key_exists('salary_min', $lineInput)) {
                                    $updates['salary_min'] = $lineInput['salary_min'] !== null && $lineInput['salary_min'] !== '' ? $lineInput['salary_min'] : null;
                                }
                                if (array_key_exists('salary_max', $lineInput)) {
                                    $updates['salary_max'] = $lineInput['salary_max'] !== null && $lineInput['salary_max'] !== '' ? $lineInput['salary_max'] : null;
                                }
                                if (array_key_exists('salary_min', $updates) || array_key_exists('salary_max', $updates)) {
                                    $updates['salary_currency_code'] = $hasSalary ? $companyCurrency : null;
                                }
                                if (array_key_exists('line_notes', $lineInput)) {
                                    $updates['line_notes'] = $lineInput['line_notes'];
                                }

                                if (! empty($updates)) {
                                    $targetLine->update($updates);
                                }
                            } else {
                                $created = RecruitmentRequirementLine::create([
                                    'company_id' => $companyId,
                                    'recruitment_requirement_id' => $locked->id,
                                    'position_id' => (int) $lineInput['position_id'],
                                    'required_headcount' => (int) ($lineInput['required_headcount'] ?? 1),
                                    'line_notes' => $lineInput['line_notes'] ?? null,
                                    'status' => RequirementLineStatus::Open,
                                    'salary_min' => isset($lineInput['salary_min']) && $lineInput['salary_min'] !== '' ? $lineInput['salary_min'] : null,
                                    'salary_max' => isset($lineInput['salary_max']) && $lineInput['salary_max'] !== '' ? $lineInput['salary_max'] : null,
                                    'salary_currency_code' => $hasSalary ? $companyCurrency : null,
                                ]);
                                $existingLines->push($created);
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

        $newStoredFilePath = $storedFilePath;

        if ($shouldNotifyReassignment && $reassignmentTransition instanceof RecruitmentRequirementStatusTransition) {
            SendRequirementLifecycleEmails::pendingReassigned($result, $reassignmentTransition);
        }

        return $result;
    }
}
