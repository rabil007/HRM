<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use App\Support\Recruitment\GenerateRequirementNumber;
use App\Support\Recruitment\RecordRequirementStatusTransition;
use App\Support\Recruitment\RecruiterOptionsQuery;
use App\Support\Recruitment\SyncRequirementNotificationRecipients;
use App\Support\Settings\CompanyCurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RepeatRequirementAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(
        RecruitmentRequirement $sourceRequirement,
        int $userId,
        array $data,
    ): RecruitmentRequirement {
        return DB::transaction(function () use ($sourceRequirement, $userId, $data): RecruitmentRequirement {
            /** @var RecruitmentRequirement $source */
            $source = RecruitmentRequirement::query()
                ->where('id', $sourceRequirement->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($source->status, [RequirementStatus::Completed, RequirementStatus::Cancelled], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only completed or cancelled requirements can be repeated.',
                ]);
            }

            $companyId = (int) $source->company_id;
            $assignedTo = array_key_exists('assigned_to', $data)
                ? ($data['assigned_to'] !== null ? (int) $data['assigned_to'] : null)
                : ($source->assigned_to !== null ? (int) $source->assigned_to : null);

            RecruiterOptionsQuery::assertEligibleApprover($assignedTo, $companyId, required: false);

            $newNumber = GenerateRequirementNumber::next($companyId);

            $newRequirement = RecruitmentRequirement::create([
                'company_id' => $companyId,
                'requirement_number' => $newNumber,
                'client_id' => $source->client_id,
                'project_id' => $source->project_id,
                'client_reference_number' => null,
                'request_received_date' => $data['request_received_date'],
                'required_by_date' => $data['required_by_date'],
                'location' => $data['location'] ?? $source->location,
                'priority' => $data['priority'] ?? $source->priority,
                'assigned_to' => $assignedTo,
                'notes' => $data['notes'] ?? null,
                'status' => RequirementStatus::Draft,
                'repeated_from_id' => $source->id,
                'opened_at' => null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $sourceLines = $source->lines->keyBy('position_id');

            foreach ($data['lines'] as $lineInput) {
                $sourceLine = $sourceLines->get($lineInput['position_id']);
                $salaryMin = array_key_exists('salary_min', $lineInput)
                    ? $lineInput['salary_min']
                    : $sourceLine?->salary_min;
                $salaryMax = array_key_exists('salary_max', $lineInput)
                    ? $lineInput['salary_max']
                    : $sourceLine?->salary_max;
                $salaryCurrency = array_key_exists('salary_currency_code', $lineInput)
                    ? $lineInput['salary_currency_code']
                    : ($sourceLine?->salary_currency_code ?? CompanyCurrency::codeForCompany($companyId));

                RecruitmentRequirementLine::create([
                    'company_id' => $companyId,
                    'recruitment_requirement_id' => $newRequirement->id,
                    'position_id' => $lineInput['position_id'],
                    'required_headcount' => $lineInput['required_headcount'],
                    'salary_min' => $salaryMin,
                    'salary_max' => $salaryMax,
                    'salary_currency_code' => $salaryCurrency,
                    'line_notes' => $lineInput['line_notes'] ?? null,
                    'status' => RequirementLineStatus::Open,
                ]);
            }

            if (array_key_exists('notification_recipient_ids', $data)) {
                SyncRequirementNotificationRecipients::sync(
                    $newRequirement,
                    is_array($data['notification_recipient_ids']) ? $data['notification_recipient_ids'] : [],
                );
            }

            RecordRequirementStatusTransition::handle(
                $newRequirement,
                null,
                RequirementStatus::Draft,
                $userId,
            );

            activity('recruitment')
                ->causedBy($userId)
                ->performedOn($newRequirement)
                ->withProperties([
                    'company_id' => $companyId,
                    'requirement_number' => $newNumber,
                    'repeated_from_id' => $sourceRequirement->id,
                    'repeated_from_number' => $sourceRequirement->requirement_number,
                ])
                ->log("Requirement {$newNumber} repeated from {$sourceRequirement->requirement_number}.");

            return $newRequirement->load(['lines.position', 'client', 'project', 'notificationRecipients.user']);
        });
    }
}
