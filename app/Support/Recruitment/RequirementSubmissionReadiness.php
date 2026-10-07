<?php

namespace App\Support\Recruitment;

use App\Enums\Recruitment\RequirementLineStatus;
use App\Models\Client;
use App\Models\Position;
use App\Models\Project;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use App\Models\User;
use App\Support\MasterData\ClientAssignmentRules;
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
    public static function for(
        RecruitmentRequirement $requirement,
        ?User $viewer = null,
        ?RequirementSubmissionReadinessLookup $lookup = null,
    ): array {
        $items = self::items($requirement, $viewer, $lookup);
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
    public static function items(
        RecruitmentRequirement $requirement,
        ?User $viewer = null,
        ?RequirementSubmissionReadinessLookup $lookup = null,
    ): array {
        $companyId = (int) $requirement->company_id;
        $items = [];

        $items[] = self::item(
            'client',
            'Client selected',
            $requirement->client_id !== null,
            'Select a client.',
        );

        if ($requirement->client_id !== null) {
            $client = $requirement->relationLoaded('client')
                ? $requirement->client
                : Client::withTrashed()->find($requirement->client_id);
            $clientReady = $client !== null && ! $client->trashed() && $client->is_active;
            $items[] = self::item(
                'client_active',
                'Client is active',
                $clientReady,
                $client === null || $client->trashed()
                    ? 'The selected client is no longer available.'
                    : 'The selected client is inactive.',
            );
        }

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

        if ($requirement->project_id !== null) {
            $items[] = self::item(
                'project_requires_client',
                'Project requires client',
                $requirement->client_id !== null,
                'Select a client before assigning a project.',
            );

            $project = $requirement->relationLoaded('project')
                ? $requirement->project
                : Project::withTrashed()->find($requirement->project_id);
            $projectReady = $project !== null
                && ! $project->trashed()
                && $project->is_active;
            $items[] = self::item(
                'project_active',
                'Project is active',
                $projectReady,
                $project === null || $project->trashed()
                    ? 'The selected project is no longer available.'
                    : 'The selected project is inactive.',
            );

            if ($projectReady && $requirement->client_id !== null) {
                $projectClientMessage = self::projectClientMessage(
                    (int) $requirement->client_id,
                    (int) $requirement->project_id,
                    $project,
                    $lookup,
                );
                $items[] = self::item(
                    'project_client_link',
                    'Project belongs to client',
                    $projectClientMessage === null,
                    $projectClientMessage ?? 'The selected project is not assigned to the selected client.',
                );
            }
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
            && ($lookup?->isEligibleApprover($assignedTo, $companyId)
                ?? RecruiterOptionsQuery::isEligibleApprover($assignedTo, $companyId));
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
                ->with('position:id,title,company_id,status,deleted_at')
                ->get();

        $items[] = self::item(
            'active_positions',
            'At least one position line',
            $activeLines->isNotEmpty(),
            'At least one active position line is required.',
        );

        foreach ($activeLines as $line) {
            $title = (string) ($line->position?->title ?? 'Position');
            $position = $line->relationLoaded('position')
                ? $line->position
                : ($line->position_id !== null ? Position::withTrashed()->find($line->position_id) : null);

            $positionReady = $position !== null
                && ! $position->trashed()
                && (int) $position->company_id === $companyId
                && $position->status === 'active';

            $items[] = self::item(
                'position_line_'.$line->id,
                "Position for {$title}",
                $positionReady,
                $position === null || $position->trashed()
                    ? "The position for {$title} is no longer available."
                    : ((int) $position->company_id !== $companyId
                        ? "The position for {$title} is not valid for this company."
                        : "The position for {$title} is inactive."),
            );

            $headcountReady = (int) $line->required_headcount >= 1;
            $items[] = self::item(
                'headcount_line_'.$line->id,
                "Headcount for {$title}",
                $headcountReady,
                "Enter a required headcount of at least 1 for {$title}.",
            );

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
        $requirement->loadMissing(['lines.position']);
        $readiness = self::for($requirement);
        if ($readiness['ready']) {
            return;
        }

        $messages = [];
        foreach ($readiness['items'] as $item) {
            if ($item['ready'] || $item['message'] === null) {
                continue;
            }

            $field = self::fieldForReadinessKey($item['key'], $requirement);

            $messages[$field][] = $item['message'];
        }

        $flat = [];
        foreach ($messages as $field => $fieldMessages) {
            $flat[$field] = $fieldMessages[0];
        }

        foreach ($readiness['items'] as $item) {
            if ($item['ready'] || $item['message'] === null) {
                continue;
            }

            if (str_starts_with($item['key'], 'salary_line_')) {
                $flat['salary'] = $item['message'];
                break;
            }
        }

        $flat['submission_readiness'] = 'Requirement is not ready for approval. Complete the remaining required fields.';

        throw ValidationException::withMessages($flat);
    }

    private static function fieldForReadinessKey(string $key, RecruitmentRequirement $requirement): string
    {
        if (str_starts_with($key, 'salary_line_')) {
            $lineId = (int) substr($key, strlen('salary_line_'));

            return self::positionFieldForLineId($requirement, $lineId, 'salary_min');
        }

        if (str_starts_with($key, 'headcount_line_')) {
            $lineId = (int) substr($key, strlen('headcount_line_'));

            return self::positionFieldForLineId($requirement, $lineId, 'required_headcount');
        }

        if (str_starts_with($key, 'position_line_')) {
            $lineId = (int) substr($key, strlen('position_line_'));

            return self::positionFieldForLineId($requirement, $lineId, 'position_id');
        }

        return match ($key) {
            'client', 'client_active' => 'client_id',
            'request_received_date' => 'request_received_date',
            'required_by_date', 'required_by_date_order' => 'required_by_date',
            'assigned_recruiter', 'assigned_recruiter_eligible', 'self_approval' => 'assigned_to',
            'active_positions' => 'positions',
            'project_requires_client', 'project_active', 'project_client_link' => 'project_id',
            default => 'status',
        };
    }

    private static function positionFieldForLineId(
        RecruitmentRequirement $requirement,
        int $lineId,
        string $suffix,
    ): string {
        $lineIndex = $requirement->relationLoaded('lines')
            ? $requirement->lines->search(fn ($line) => (int) $line->id === $lineId)
            : false;

        return $lineIndex === false
            ? 'positions'
            : "positions.{$lineIndex}.{$suffix}";
    }

    private static function projectClientMessage(
        int $clientId,
        int $projectId,
        ?Project $project,
        ?RequirementSubmissionReadinessLookup $lookup,
    ): ?string {
        $clientIds = $lookup?->clientIdsForProject($projectId);

        if ($clientIds === null && $project !== null && $project->relationLoaded('clients')) {
            $clientIds = $project->clients
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
        }

        if ($clientIds !== null) {
            if ($clientIds === []) {
                return ClientAssignmentRules::PROJECT_MISSING_CLIENT_MESSAGE;
            }

            if (! in_array($clientId, $clientIds, true)) {
                return 'The selected project is not assigned to the selected client.';
            }

            return null;
        }

        return ClientAssignmentRules::projectClientInconsistencyMessage($clientId, $projectId);
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
