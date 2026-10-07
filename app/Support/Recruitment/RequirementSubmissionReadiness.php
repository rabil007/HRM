<?php

namespace App\Support\Recruitment;

use App\Enums\Recruitment\RequirementLineStatus;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Authoritative submission-readiness checklist for Draft/Returned requirements.
 *
 * @phpstan-type ReadinessItem array{
 *     key: string,
 *     label: string,
 *     ready: bool,
 *     message: string|null
 * }
 */
final class RequirementSubmissionReadiness
{
    /**
     * @return array{
     *     ready: bool,
     *     remaining_count: int,
     *     items: list<ReadinessItem>
     * }
     */
    public static function for(RecruitmentRequirement $requirement, ?User $viewer = null): array
    {
        $items = self::items($requirement, $viewer);
        $remaining = collect($items)->where('ready', false)->values();

        return [
            'ready' => $remaining->isEmpty(),
            'remaining_count' => $remaining->count(),
            'items' => $items,
        ];
    }

    /**
     * @return list<ReadinessItem>
     */
    public static function items(RecruitmentRequirement $requirement, ?User $viewer = null): array
    {
        $companyId = (int) $requirement->company_id;
        $items = [];

        $items[] = self::item(
            'client',
            'Client selected',
            $requirement->client_id !== null,
            'Select a client.',
        );

        $items[] = self::item(
            'request_received_date',
            'Request received date',
            $requirement->request_received_date !== null,
            'Enter the Request Received from Client date.',
        );

        $items[] = self::item(
            'required_by_date',
            'Required-by date',
            $requirement->required_by_date !== null,
            'Enter the required-by date.',
        );

        if (
            $requirement->request_received_date !== null
            && $requirement->required_by_date !== null
            && $requirement->required_by_date->lt($requirement->request_received_date)
        ) {
            $items[] = self::item(
                'required_by_date_order',
                'Required-by date order',
                false,
                'Required-by date must be on or after the Request Received from Client date.',
            );
        } else {
            $items[] = self::item(
                'required_by_date_order',
                'Required-by date order',
                true,
                null,
            );
        }

        $assignedTo = $requirement->assigned_to !== null ? (int) $requirement->assigned_to : null;
        $hasAssignee = $assignedTo !== null;
        $items[] = self::item(
            'assigned_recruiter',
            'Assigned recruiter',
            $hasAssignee,
            'Assign an approving recruiter.',
        );

        $eligibleAssignee = $hasAssignee
            && RecruiterOptionsQuery::isEligibleApprover($assignedTo, $companyId);
        $items[] = self::item(
            'assigned_recruiter_eligible',
            'Assigned recruiter is eligible',
            ! $hasAssignee || $eligibleAssignee,
            'The selected recruiter must be an active company member with recruitment approval permission.',
        );

        $selfApproval = RequirementWorkflowAuthorization::blocksSelfApproval($requirement);
        $items[] = self::item(
            'self_approval',
            'Requester and recruiter are different',
            ! $selfApproval,
            'The requester cannot also be the assigned recruiter. Self-approval is not allowed.',
        );

        /** @var Collection<int, RecruitmentRequirementLine> $activeLines */
        $activeLines = $requirement->relationLoaded('lines')
            ? $requirement->lines->filter(fn ($line) => $line->status !== RequirementLineStatus::Cancelled)
            : $requirement->lines()
                ->where('status', '!=', RequirementLineStatus::Cancelled->value)
                ->with('position:id,title')
                ->get();

        $items[] = self::item(
            'active_positions',
            'At least one position line',
            $activeLines->isNotEmpty(),
            'At least one active position line is required.',
        );

        foreach ($activeLines as $line) {
            $title = (string) ($line->position?->title ?? 'Position');
            $key = 'salary_line_'.$line->id;
            $salaryReady = self::lineSalaryIsReady($line);
            $items[] = self::item(
                $key,
                "Salary range for {$title}",
                $salaryReady,
                $salaryReady ? null : "Complete a valid salary range for {$title}.",
            );
        }

        if ($viewer !== null && ! RequirementWorkflowAuthorization::canSubmit($viewer, $requirement)) {
            // Ownership/permission is not a field checklist item for readiness display when
            // the viewer is not the requester; readiness is still useful for creators.
        }

        return $items;
    }

    public static function assertReady(RecruitmentRequirement $requirement): void
    {
        $readiness = self::for($requirement);
        if ($readiness['ready']) {
            return;
        }

        $messages = [];
        foreach ($readiness['items'] as $item) {
            if ($item['ready'] || $item['message'] === null) {
                continue;
            }

            $field = match ($item['key']) {
                'client' => 'client_id',
                'request_received_date' => 'request_received_date',
                'required_by_date', 'required_by_date_order' => 'required_by_date',
                'assigned_recruiter', 'assigned_recruiter_eligible', 'self_approval' => 'assigned_to',
                'active_positions' => 'lines',
                default => str_starts_with($item['key'], 'salary_line_') ? 'salary' : 'status',
            };

            $messages[$field][] = $item['message'];
        }

        $flat = [];
        foreach ($messages as $field => $fieldMessages) {
            $flat[$field] = $fieldMessages[0];
        }

        $flat['submission_readiness'] = 'Requirement is not ready for approval. Complete the remaining required fields.';

        throw ValidationException::withMessages($flat);
    }

    private static function lineSalaryIsReady(RecruitmentRequirementLine $line): bool
    {
        if ($line->salary_min === null || $line->salary_max === null) {
            return false;
        }

        if (! is_numeric($line->salary_min) || ! is_numeric($line->salary_max)) {
            return false;
        }

        $min = (float) $line->salary_min;
        $max = (float) $line->salary_max;

        if ($min < 0 || $max < 0 || $max < $min) {
            return false;
        }

        if (
            preg_match('/^\d+(\.\d{1,2})?$/', (string) $line->salary_min) !== 1
            || preg_match('/^\d+(\.\d{1,2})?$/', (string) $line->salary_max) !== 1
        ) {
            return false;
        }

        return true;
    }

    /**
     * @return ReadinessItem
     */
    private static function item(string $key, string $label, bool $ready, ?string $message): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'ready' => $ready,
            'message' => $ready ? null : $message,
        ];
    }
}
