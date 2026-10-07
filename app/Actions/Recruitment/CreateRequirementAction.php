<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementAttachment;
use App\Models\RecruitmentRequirementLine;
use App\Models\User;
use App\Support\Recruitment\GenerateRequirementNumber;
use App\Support\Recruitment\RecordRequirementStatusTransition;
use App\Support\Recruitment\RecruiterOptionsQuery;
use App\Support\Recruitment\RequirementAttachmentStorage;
use App\Support\Recruitment\SyncRequirementNotificationRecipients;
use App\Support\Settings\CompanyCurrency;
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
        $submitAfterCreate = (bool) ($data['submit_for_approval'] ?? false);

        try {
            $requirement = DB::transaction(function () use ($companyId, $userId, $data, $attachment, $submitAfterCreate, &$storedFilePath): RecruitmentRequirement {
                $assignedTo = array_key_exists('assigned_to', $data)
                    ? ($data['assigned_to'] !== null ? (int) $data['assigned_to'] : null)
                    : null;
                RecruiterOptionsQuery::assertEligibleApprover($assignedTo, $companyId, required: false);

                $requirementNumber = GenerateRequirementNumber::next($companyId);
                $status = RequirementStatus::Draft;

                $requirement = RecruitmentRequirement::create([
                    'company_id' => $companyId,
                    'requirement_number' => $requirementNumber,
                    'client_id' => $data['client_id'],
                    'project_id' => $data['project_id'] ?? null,
                    'client_reference_number' => null,
                    'request_received_date' => $data['request_received_date'],
                    'required_by_date' => $data['required_by_date'],
                    'location' => $data['location'] ?? null,
                    'priority' => $data['priority'],
                    'assigned_to' => $assignedTo,
                    'notes' => $data['notes'] ?? null,
                    'status' => $status,
                    'repeated_from_id' => $data['repeated_from_id'] ?? null,
                    'opened_at' => null,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);

                $companyCurrency = CompanyCurrency::codeForCompany($companyId);

                foreach ($data['lines'] as $lineData) {
                    $hasSalary = (isset($lineData['salary_min']) && $lineData['salary_min'] !== null && $lineData['salary_min'] !== '')
                        || (isset($lineData['salary_max']) && $lineData['salary_max'] !== null && $lineData['salary_max'] !== '');

                    RecruitmentRequirementLine::create([
                        'company_id' => $companyId,
                        'recruitment_requirement_id' => $requirement->id,
                        'position_id' => $lineData['position_id'],
                        'required_headcount' => $lineData['required_headcount'],
                        'line_notes' => $lineData['line_notes'] ?? null,
                        'status' => RequirementLineStatus::Open,
                        'salary_min' => isset($lineData['salary_min']) && $lineData['salary_min'] !== '' ? $lineData['salary_min'] : null,
                        'salary_max' => isset($lineData['salary_max']) && $lineData['salary_max'] !== '' ? $lineData['salary_max'] : null,
                        'salary_currency_code' => $hasSalary ? $companyCurrency : null,
                    ]);
                }

                if (array_key_exists('notification_recipient_ids', $data)) {
                    SyncRequirementNotificationRecipients::sync(
                        $requirement,
                        is_array($data['notification_recipient_ids'] ?? null)
                            ? $data['notification_recipient_ids']
                            : [],
                    );
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

                // Record Draft only for intentional "Save as Draft". Direct create-and-submit
                // still persists as Draft internally, but the first user-visible event should be
                // submission (or Draft if that immediate submission fails).
                if (! $submitAfterCreate) {
                    RecordRequirementStatusTransition::handle(
                        $requirement,
                        null,
                        RequirementStatus::Draft,
                        $userId,
                    );
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

                return $requirement->load(['lines.position', 'client', 'project', 'attachments', 'notificationRecipients.user']);
            });
        } catch (Throwable $exception) {
            if ($storedFilePath !== null) {
                Storage::disk(RequirementAttachmentStorage::DISK)->delete($storedFilePath);
            }

            throw $exception;
        }

        if ($submitAfterCreate) {
            $actor = User::query()->findOrFail($userId);

            try {
                return app(SubmitRequirementForApprovalAction::class)->execute($requirement, $actor);
            } catch (Throwable $exception) {
                $requirement->refresh();

                if ($requirement->status === RequirementStatus::Draft) {
                    RecordRequirementStatusTransition::ensureDraftCreated($requirement, $userId);
                }

                throw $exception;
            }
        }

        return $requirement;
    }
}
