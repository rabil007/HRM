<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewMovementCorrectionStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\User;
use App\Support\CrewAccommodation\CrewAccommodationService;
use App\Support\CrewOperations\CrewOperationsSettings;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\Payroll\CrewTimeline\CrewPayableDateOwnership;
use App\Support\Positions\CrewPositionCatalog;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class CrewAssignmentPresenter
{
    /**
     * @return array<string, mixed>
     */
    /**
     * @param  list<int>|null  $authorizedReliefEmployeeIds
     */
    public static function listItem(CrewAssignment $assignment, ?User $user = null, ?array $authorizedReliefEmployeeIds = null): array
    {
        $current = $assignment->currentPhase;
        $timezone = self::companyTimezone($assignment);
        $tourProgress = (new CrewTourProgress)->forAssignment($assignment, null, $timezone);
        // Dynamic request-scoped values are stored via Eloquent attributes; do not use property_exists.
        $relief = $assignment->relief_readiness instanceof CrewReliefReadinessResult
            ? $assignment->relief_readiness
            : (new CrewReliefReadinessResolver)->forSourceAssignment($assignment, null, null, $timezone);
        $relief = $relief->sanitizeForViewer($user, (int) $assignment->company_id, $authorizedReliefEmployeeIds);
        $readiness = $assignment->mobilisation_readiness instanceof CrewMobilisationReadinessResult
            ? $assignment->mobilisation_readiness
            : (new CrewMobilisationReadinessResolver)->forAssignment($assignment, $user, includeHrefs: false);
        $availableActions = CrewMovementAvailableActions::for($assignment);
        $recommended = (new CrewAssignmentRecommendedActionResolver)->forAssignment(
            $assignment,
            $availableActions,
            $readiness,
            $relief,
            $user,
        );

        return [
            'id' => $assignment->id,
            'assignment_no' => $assignment->assignment_no,
            'status' => $assignment->status->value,
            'status_label' => $assignment->status->label(),
            'employee' => $assignment->employee ? [
                'id' => $assignment->employee->id,
                'name' => $assignment->employee->name,
                'employee_no' => $assignment->employee->employee_no,
                'image' => $assignment->employee->image,
            ] : null,
            'position' => self::positionPayload($assignment),
            'vessel' => $assignment->vessel ? [
                'id' => $assignment->vessel->id,
                'name' => $assignment->vessel->name,
            ] : null,
            'client' => $assignment->client ? [
                'id' => $assignment->client->id,
                'name' => $assignment->client->name,
            ] : null,
            'current_phase' => $current ? [
                'id' => $current->id,
                'code' => $current->phase_code->value,
                'label' => $current->phase_code->label(),
                'status' => $current->status->value,
                'started_at' => self::formatDateTime($current->actual_start_at, $timezone),
            ] : null,
            'days_in_phase' => self::wholeDaysSince($current?->actual_start_at, $timezone),
            'planned_join_at' => $assignment->planned_join_at?->toDateString(),
            'planned_arrival_at' => $assignment->planned_arrival_at?->toDateString(),
            'planned_signoff_at' => $assignment->planned_signoff_at?->toDateString(),
            'planned_travel_at' => $assignment->planned_travel_at?->toDateString(),
            'actual_arrival_at' => CrewArrivalResolver::date($assignment, $timezone),
            'actual_join_at' => self::latestOnVesselPhase($assignment)?->actual_start_at?->toDateString(),
            'actual_disembarkation_at' => self::latestOnVesselPhase($assignment)?->actual_end_at?->toDateString(),
            'created_at' => $assignment->created_at?->toDateString(),
            'company_timezone' => $timezone,
            'is_editable' => CrewAssignmentEditability::isEditable($assignment),
            ...$tourProgress,
            ...$relief->toArray(),
            'warnings' => is_array($assignment->attention_warnings)
                ? $assignment->attention_warnings
                : CrewMovementAttentionQuery::forAssignment($assignment, $tourProgress),
            'available_actions' => $availableActions,
            'mobilisation_readiness' => $readiness->applies ? $readiness->toArray(compact: true) : null,
            'recommended_action' => $recommended?->toArray(),
            'movement_context' => self::movementContext($assignment, $tourProgress),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  list<int>|null  $authorizedReliefEmployeeIds
     */
    public static function detail(CrewAssignment $assignment, ?User $user = null, ?array $authorizedReliefEmployeeIds = null): array
    {
        $current = $assignment->currentPhase;
        $timezone = self::companyTimezone($assignment);
        $onVesselPhase = self::latestOnVesselPhase($assignment);
        $trainingPhase = self::latestPhase($assignment, CrewPhaseCode::Training);
        $tourProgress = (new CrewTourProgress)->forAssignment($assignment, null, $timezone);
        $reliefResolver = new CrewReliefReadinessResolver;
        $relief = $reliefResolver->forPreloadedPlan(
            $assignment,
            $reliefResolver->findActiveReliefPlan(
                (int) $assignment->company_id,
                (int) $assignment->id,
            ),
            null,
            $timezone,
        );
        $relief = $relief->sanitizeForViewer($user, (int) $assignment->company_id, $authorizedReliefEmployeeIds);
        $readiness = (new CrewMobilisationReadinessResolver)->forAssignment($assignment, $user);
        $availableActions = CrewMovementAvailableActions::for($assignment);
        $recommended = (new CrewAssignmentRecommendedActionResolver)->forAssignment(
            $assignment,
            $availableActions,
            $readiness,
            $relief,
            $user,
        );
        $relieves = self::relievesContext($assignment, $user);
        $tourRepair = (new ApplyMissingCrewTourOfDuty)->inspect($assignment);

        $canViewCorrections = $user?->can('crew_operations.corrections.view') ?? false;
        $canRequestCorrections = $user?->can('crew_operations.corrections.request') ?? false;
        $canOverrideCorrections = $user?->can('crew_operations.corrections.override') ?? false;
        $canSeePending = $canViewCorrections || $canRequestCorrections || $canOverrideCorrections;

        $allocatedServiceDays = self::allocatedServiceDays($assignment, $timezone);

        $phaseTimeline = $assignment->phases
            ->map(function ($phase) use ($canViewCorrections, $canSeePending, $user, $allocatedServiceDays) {
                $hasPending = $canSeePending
                    && $phase->relationLoaded('pendingCorrections')
                    ? $phase->pendingCorrections->isNotEmpty()
                    : false;
                $hasApproved = $canViewCorrections
                    && $phase->relationLoaded('corrections')
                    ? $phase->corrections->where('status', CrewMovementCorrectionStatus::Approved)->isNotEmpty()
                    : false;
                $employeeTrainingId = $phase->relationLoaded('employeeTraining')
                    ? $phase->employeeTraining?->id
                    : null;

                $ownPendingCorrection = null;
                if ($hasPending && $user !== null && $phase->relationLoaded('pendingCorrections')) {
                    $ownPendingCorrection = $phase->pendingCorrections
                        ->first(fn ($c) => (int) $c->requested_by === (int) $user->id);
                }

                return [
                    'id' => $phase->id,
                    'sequence' => $phase->sequence,
                    'phase_code' => $phase->phase_code->value,
                    'phase_label' => $phase->phase_code->label(),
                    'status' => $phase->status->value,
                    'status_label' => $phase->status->label(),
                    'planned_start_at' => $phase->planned_start_at?->toDateString(),
                    'planned_end_at' => $phase->planned_end_at?->toDateString(),
                    'actual_start_at' => $phase->actual_start_at?->toDateString(),
                    'actual_end_at' => $phase->actual_end_at?->toDateString(),
                    'allocated_service_days' => $allocatedServiceDays[(int) $phase->id] ?? null,
                    'details' => $phase->details,
                    'remarks' => $phase->remarks,
                    'has_pending_correction' => $hasPending,
                    'has_approved_correction' => $hasApproved,
                    'own_pending_correction_id' => $ownPendingCorrection?->id,
                    'can_cancel_pending' => $ownPendingCorrection !== null,
                    'employee_training_id' => $employeeTrainingId,
                ];
            })
            ->sortBy('sequence')
            ->values()
            ->all();

        return [
            'id' => $assignment->id,
            'assignment_no' => $assignment->assignment_no,
            'status' => $assignment->status->value,
            'status_label' => $assignment->status->label(),
            'employee' => $assignment->employee ? [
                'id' => $assignment->employee->id,
                'name' => $assignment->employee->name,
                'employee_no' => $assignment->employee->employee_no,
                'image' => $assignment->employee->image,
            ] : null,
            'position' => self::positionPayload($assignment, includeTourDays: true),
            'vessel' => $assignment->vessel ? [
                'id' => $assignment->vessel->id,
                'name' => $assignment->vessel->name,
            ] : null,
            'client' => $assignment->client ? [
                'id' => $assignment->client->id,
                'name' => $assignment->client->name,
            ] : null,
            'current_phase' => $current ? [
                'id' => $current->id,
                'code' => $current->phase_code->value,
                'label' => $current->phase_code->label(),
                'status' => $current->status->value,
                'status_label' => $current->status->label(),
                'started_at' => self::formatDateTime($current->actual_start_at, $timezone),
            ] : null,
            'days_in_phase' => self::wholeDaysSince($current?->actual_start_at, $timezone),
            'days_onboard' => self::wholeDaysBetween(
                $onVesselPhase?->actual_start_at,
                $onVesselPhase?->actual_end_at ?? now($timezone),
                $timezone,
            ),
            'days_in_training' => self::wholeDaysBetween(
                $trainingPhase?->actual_start_at,
                $trainingPhase?->actual_end_at ?? (
                    $trainingPhase?->status === CrewPhaseStatus::Active ? now($timezone) : null
                ),
                $timezone,
            ),
            'planned_join_at' => $assignment->planned_join_at?->toDateString(),
            'planned_arrival_at' => $assignment->planned_arrival_at?->toDateString(),
            'planned_signoff_at' => $assignment->planned_signoff_at?->toDateString(),
            'planned_travel_at' => $assignment->planned_travel_at?->toDateString(),
            'actual_arrival_at' => CrewArrivalResolver::date($assignment, $timezone),
            'actual_join_at' => $onVesselPhase?->actual_start_at?->toDateString(),
            'actual_disembarkation_at' => $onVesselPhase?->actual_end_at?->toDateString(),
            'started_at' => $assignment->started_at?->toDateString(),
            'closed_at' => $assignment->closed_at?->toDateString(),
            'source' => $assignment->source,
            'remarks' => $assignment->remarks,
            'created_at' => $assignment->created_at?->toDateString(),
            'updated_at' => $assignment->updated_at?->toDateString(),
            'company_timezone' => $timezone,
            'is_editable' => CrewAssignmentEditability::isEditable($assignment),
            'can_apply_tour_of_duty' => $tourRepair !== null && ($tourRepair['is_eligible'] ?? false),
            'current_position_tour_days' => $tourRepair !== null ? ($tourRepair['tour_of_duty_days'] ?? null) : null,
            'suggested_planned_signoff_at' => $tourRepair !== null && isset($tourRepair['calculated_planned_signoff_at']) && $tourRepair['calculated_planned_signoff_at'] !== null
                ? $tourRepair['calculated_planned_signoff_at']->copy()->timezone($timezone)->toDateString()
                : null,
            ...$tourProgress,
            ...$relief->toArray(),
            'relieves' => $relieves,
            'phase_timeline' => $phaseTimeline,
            'warnings' => CrewMovementAttentionQuery::forAssignment($assignment, $tourProgress),
            'available_actions' => $availableActions,
            'mobilisation_readiness' => $readiness->applies ? $readiness->toArray() : null,
            'recommended_action' => $recommended?->toArray(),
            'planning_assignment_id' => $assignment->planningAssignment?->id,
            'previous_assignment' => $assignment->previousAssignment ? [
                'id' => $assignment->previousAssignment->id,
                'assignment_no' => $assignment->previousAssignment->assignment_no,
                'status' => $assignment->previousAssignment->status->value,
                'status_label' => $assignment->previousAssignment->status->label(),
                'source' => $assignment->previousAssignment->source,
                'vessel_name' => $assignment->previousAssignment->vessel?->name,
                'closed_at' => $assignment->previousAssignment->closed_at?->toDateString(),
            ] : null,
            'next_assignments' => $assignment->relationLoaded('nextAssignments')
                ? $assignment->nextAssignments
                    ->sortBy('id')
                    ->values()
                    ->map(fn (CrewAssignment $next): array => [
                        'id' => $next->id,
                        'assignment_no' => $next->assignment_no,
                        'status' => $next->status->value,
                        'status_label' => $next->status->label(),
                        'source' => $next->source,
                        'vessel_name' => $next->vessel?->name,
                        'started_at' => $next->started_at?->toDateString(),
                    ])
                    ->all()
                : [],
            'accommodation' => app(CrewAccommodationService::class)->assignmentAccommodationSummary($assignment, $timezone),
            'movement_context' => [
                ...self::movementContext($assignment, $tourProgress),
                'active_on_vessel_elsewhere' => self::activeOnVesselElsewhere($assignment, $user),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $tourProgress
     * @return array<string, mixed>
     */
    public static function movementContext(CrewAssignment $assignment, ?array $tourProgress = null): array
    {
        $timezone = self::companyTimezone($assignment);
        $current = $assignment->currentPhase;
        $onVesselPhase = self::latestOnVesselPhase($assignment);
        $trainingPhase = self::latestPhase($assignment, CrewPhaseCode::Training);
        $tourProgress ??= (new CrewTourProgress)->forAssignment($assignment, null, $timezone);

        return [
            'assignment_id' => $assignment->id,
            'assignment_no' => $assignment->assignment_no,
            'employee_id' => $assignment->employee_id,
            'employee_name' => $assignment->employee?->name,
            'employee_no' => $assignment->employee?->employee_no,
            'current_phase_code' => $current?->phase_code->value,
            'current_phase_label' => $current?->phase_code->label(),
            'current_phase_started_at' => self::formatDateTime($current?->actual_start_at, $timezone),
            'days_in_phase' => self::wholeDaysSince($current?->actual_start_at, $timezone),
            'days_onboard' => self::wholeDaysBetween(
                $onVesselPhase?->actual_start_at,
                $onVesselPhase?->actual_end_at ?? now($timezone),
                $timezone,
            ),
            'days_in_training' => self::wholeDaysBetween(
                $trainingPhase?->actual_start_at,
                $trainingPhase?->actual_end_at ?? (
                    $trainingPhase?->status === CrewPhaseStatus::Active ? now($timezone) : null
                ),
                $timezone,
            ),
            'vessel_id' => $assignment->vessel_id,
            'vessel_name' => $assignment->vessel?->name,
            'position_id' => self::resolvedPositionId($assignment),
            'position_name' => self::positionPayload($assignment)['name'] ?? null,
            'client_id' => $assignment->client_id,
            'client_name' => $assignment->client?->name,
            'planned_join_at' => $assignment->planned_join_at?->toDateString(),
            'planned_arrival_at' => $assignment->planned_arrival_at?->toDateString(),
            'planned_signoff_at' => $assignment->planned_signoff_at?->toDateString(),
            'planned_travel_at' => $assignment->planned_travel_at?->toDateString(),
            'actual_arrival_at' => CrewArrivalResolver::date($assignment, $timezone),
            'actual_join_at' => $onVesselPhase?->actual_start_at?->toDateString(),
            'actual_disembarkation_at' => $onVesselPhase?->actual_end_at?->toDateString(),
            'training_provider' => is_array($trainingPhase?->details) ? ($trainingPhase->details['provider'] ?? null) : null,
            'training_course' => is_array($trainingPhase?->details) ? ($trainingPhase->details['course'] ?? null) : null,
            'training_course_id' => is_array($trainingPhase?->details) ? ($trainingPhase->details['course_id'] ?? null) : null,
            'sync_training_enabled' => CrewOperationsSettings::syncTrainingToEmployeeTrainingEnabled((int) $assignment->company_id),
            'allow_future_actual_movement_dates' => CrewOperationsSettings::allowFutureActualMovementDates((int) $assignment->company_id),
            'training_started_at' => self::formatDateTime($trainingPhase?->actual_start_at, $timezone),
            'training_expected_completion_at' => $trainingPhase?->planned_end_at?->toDateString(),
            'company_timezone' => $timezone,
            'pre_join_accommodation' => self::preJoinAccommodationContext($assignment, $timezone),
            'post_signoff_accommodation' => self::postSignoffAccommodationContext($assignment, $timezone),
            ...$tourProgress,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function preJoinAccommodationContext(CrewAssignment $assignment, string $timezone): ?array
    {
        $currentCode = $assignment->currentPhase?->phase_code;

        if (! in_array($currentCode, [CrewPhaseCode::JoinStandby, CrewPhaseCode::Training], true)) {
            return null;
        }

        return app(CrewAccommodationService::class)->preJoinContext($assignment, $timezone);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function postSignoffAccommodationContext(CrewAssignment $assignment, string $timezone): ?array
    {
        if ($assignment->currentPhase?->phase_code !== CrewPhaseCode::DemobStandby) {
            return null;
        }

        return app(CrewAccommodationService::class)->postSignoffContext($assignment, $timezone);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function activeOnVesselElsewhere(CrewAssignment $assignment, ?User $user): ?array
    {
        $current = app(ActiveOnVesselAssignmentFinder::class)->find(
            (int) $assignment->company_id,
            (int) $assignment->employee_id,
            (int) $assignment->id,
        );

        if ($current === null) {
            return null;
        }

        return [
            ...$current,
            'can_transfer' => CrewAssignmentPagePermissions::canTransfer($user),
        ];
    }

    private static function companyTimezone(CrewAssignment $assignment): string
    {
        return CompanyTimezone::forCompany(
            $assignment->company ?? (int) $assignment->company_id
        );
    }

    /**
     * Present Position from an already-loaded relation only.
     *
     * Callers must eager-load `position` or run
     * {@see CrewPositionCatalog::hydrateCanonicalPositions()} before presenting.
     * Never lazy-loads; unresolved Position returns null.
     *
     * @return array{id: int, name: string, max_tour_of_duty_days?: int|null}|null
     */
    private static function positionPayload(CrewAssignment $assignment, bool $includeTourDays = false): ?array
    {
        if (! $assignment->relationLoaded('position') || $assignment->position === null) {
            return null;
        }

        $position = $assignment->position;

        $payload = [
            'id' => (int) $position->id,
            'name' => (string) $position->title,
        ];

        if ($includeTourDays) {
            $payload['max_tour_of_duty_days'] = $position->max_tour_of_duty_days !== null
                ? (int) $position->max_tour_of_duty_days
                : null;
        }

        return $payload;
    }

    private static function resolvedPositionId(CrewAssignment $assignment): ?int
    {
        return CrewPositionCatalog::resolveCrewAssignmentPositionId((int) $assignment->company_id, $assignment->position_id !== null ? (int) $assignment->position_id : null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function relievesContext(CrewAssignment $assignment, ?User $user = null): ?array
    {
        if (! $assignment->relationLoaded('planningAssignment')) {
            return null;
        }

        $planning = $assignment->planningAssignment;

        if ($planning === null || $planning->relieves_crew_assignment_id === null) {
            return null;
        }

        // Never query here — callers must eager-load relievedAssignment (+ hydrate Position).
        if (! $planning->relationLoaded('relievedAssignment')) {
            return null;
        }

        $source = $planning->relievedAssignment;

        if ($source === null || (int) $source->company_id !== (int) $assignment->company_id) {
            return null;
        }

        $sourceEmployee = $source->relationLoaded('employee') ? $source->employee : null;

        if ($user !== null
            && $sourceEmployee !== null
            && ! EmployeeVisibilityScope::canAccess($user, $sourceEmployee, (int) $assignment->company_id)) {
            return null;
        }

        $timezone = self::companyTimezone($source);
        $sourceVessel = $source->relationLoaded('vessel') ? $source->vessel : null;

        return [
            'planning_assignment_id' => (int) $planning->id,
            'source_assignment_id' => (int) $source->id,
            'source_assignment_no' => $source->assignment_no,
            'source_employee' => $sourceEmployee ? [
                'id' => $sourceEmployee->id,
                'name' => $sourceEmployee->name,
                'employee_no' => $sourceEmployee->employee_no,
            ] : null,
            'source_vessel' => $sourceVessel ? [
                'id' => $sourceVessel->id,
                'name' => $sourceVessel->name,
            ] : null,
            'source_position' => self::positionPayload($source),
            'source_planned_signoff_at' => $source->planned_signoff_at
                ?->copy()
                ->timezone($timezone)
                ->toDateString(),
        ];
    }

    private static function latestOnVesselPhase(CrewAssignment $assignment): ?CrewAssignmentPhase
    {
        return self::latestPhase($assignment, CrewPhaseCode::OnVessel);
    }

    private static function latestPhase(CrewAssignment $assignment, CrewPhaseCode $code): ?CrewAssignmentPhase
    {
        return $assignment->phases
            ->filter(fn (CrewAssignmentPhase $phase): bool => $phase->phase_code === $code)
            ->sortByDesc(fn (CrewAssignmentPhase $phase): int => (int) $phase->sequence)
            ->first();
    }

    /**
     * Allocated payable days for this assignment, using linked assignment phases
     * so a shared transfer or redeployment date is owned once.
     *
     * @return array<int, int|null>
     */
    private static function allocatedServiceDays(CrewAssignment $assignment, string $timezone): array
    {
        $companyId = (int) $assignment->company_id;
        $employeeId = (int) $assignment->employee_id;
        $analysis = CrewPayableDateOwnership::analyze(
            $assignment->phases->concat(self::siblingPhases($assignment)),
            $timezone,
            now($timezone),
            clampToToday: true,
            employeeId: $employeeId,
            companyId: $companyId,
            assignmentId: (int) $assignment->id,
        );

        $days = [];

        foreach ($assignment->phases as $phase) {
            $phaseId = (int) $phase->id;

            if ($phase->actual_start_at === null || CrewPayableDateOwnership::isExcludedPhase($phase->phase_code)) {
                $days[$phaseId] = null;

                continue;
            }

            if (isset($analysis['future_only_phase_ids'][$phaseId])) {
                $days[$phaseId] = 0;

                continue;
            }

            if (isset($analysis['countable_phase_ids'][$phaseId]) || isset($analysis['allocated_days_by_phase'][$phaseId])) {
                $days[$phaseId] = $analysis['allocated_days_by_phase'][$phaseId] ?? 0;

                continue;
            }

            $days[$phaseId] = null;
        }

        return $days;
    }

    /**
     * Linked previous/next assignments (full A→B→C chain) may compete for the same
     * payable date. Unrelated same-employee assignments are excluded.
     *
     * @return Collection<int, CrewAssignmentPhase>
     */
    private static function siblingPhases(CrewAssignment $assignment): Collection
    {
        $companyId = (int) $assignment->company_id;
        $employeeId = (int) $assignment->employee_id;
        $assignmentId = (int) $assignment->id;

        if ($employeeId < 1 || $assignmentId < 1) {
            return collect();
        }

        $chainIds = array_values(array_filter(
            CrewAssignmentLinkChain::assignmentIds($companyId, $employeeId, $assignmentId),
            static fn (int $id): bool => $id !== $assignmentId,
        ));

        if ($chainIds === []) {
            return collect();
        }

        return CrewAssignmentPhase::query()
            ->select([
                'id',
                'company_id',
                'crew_assignment_id',
                'phase_code',
                'status',
                'actual_start_at',
                'actual_end_at',
            ])
            ->where('company_id', $companyId)
            ->whereIn('status', CrewPayableDateOwnership::eligibleStatusValues())
            ->whereNotNull('actual_start_at')
            ->whereIn('crew_assignment_id', $chainIds)
            ->get();
    }

    private static function wholeDaysSince(?CarbonInterface $start, string $timezone): ?int
    {
        if ($start === null) {
            return null;
        }

        return self::wholeDaysBetween($start, now($timezone), $timezone);
    }

    private static function wholeDaysBetween(
        ?CarbonInterface $start,
        ?CarbonInterface $end,
        string $timezone,
    ): ?int {
        if ($start === null || $end === null) {
            return null;
        }

        $from = $start->copy()->timezone($timezone)->startOfDay();
        $to = $end->copy()->timezone($timezone)->startOfDay();
        $days = (int) $from->diffInDays($to, false);

        // Future-dated actual starts are data inconsistencies — never show negative
        // elapsed days. Callers surface the inconsistency via attention warnings.
        if ($days < 0) {
            return null;
        }

        return $days;
    }

    private static function formatDateTime(?CarbonInterface $value, string $timezone): ?string
    {
        return $value?->copy()->timezone($timezone)->format('Y-m-d H:i:s');
    }
}
