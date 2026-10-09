<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\PayrollPeriodStatus;
use App\Enums\PayrollWorkAllocationStatus;
use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\CrewTimesheetSegment;
use App\Models\EmployeeSeaService;
use App\Models\PayrollWorkAllocation;
use Illuminate\Validation\ValidationException;

/**
 * Downstream safety checks for privileged Void Erroneous Assignment.
 *
 * Permission alone is never sufficient; blockers are machine-readable codes.
 * Cleanup-eligible blockers may be ignored when the matching void option is confirmed.
 */
final class CrewAssignmentVoidGuard
{
    public const BLOCKED_MESSAGE = 'This assignment cannot be voided because it has already affected protected payroll, sea service, or a linked assignment. Use the appropriate correction or reversal workflow instead.';

    public const ACCOMMODATION_CLEANUP_MESSAGE = 'This assignment has accommodation history. Select "Delete linked accommodation records" to continue, or use the appropriate correction workflow instead.';

    public const SEA_SERVICE_BLOCKED_MESSAGE = 'This assignment has generated Sea Service records. To delete this erroneous assignment, also select "Delete generated Sea Service", or use the appropriate correction/reversal workflow.';

    public const DRAFT_TIMESHEET_CLEANUP_MESSAGE = 'This assignment has Draft timesheet data. Select "Remove linked Draft timesheet data" to continue.';

    public const PAYROLL_PROTECTED_MESSAGE = 'This assignment is linked to protected payroll records.';

    public const PAYROLL_APPLIED_MESSAGE = 'This assignment has applied timesheet preparation records that require a formal reversal workflow.';

    /**
     * Blocker codes that can be resolved by explicit cleanup confirmation.
     *
     * @var list<string>
     */
    public const CLEANUP_ELIGIBLE_CODES = [
        'sea_service_exists',
        'draft_timesheet_exists',
        'accommodation_history_exists',
    ];

    /**
     * @return list<array{code: string, message: string}>
     */
    public function blockers(
        CrewAssignment $assignment,
        int $companyId,
        bool $ignoreLinkedSeaService = false,
        bool $ignoreDraftTimesheet = false,
        bool $ignoreAccommodation = false,
    ): array {
        return $this->batchBlockers(
            [$assignment],
            $companyId,
            $ignoreLinkedSeaService,
            $ignoreDraftTimesheet,
            $ignoreAccommodation,
        )[(int) $assignment->id] ?? [];
    }

    public function assertCanVoid(
        CrewAssignment $assignment,
        int $companyId,
        bool $ignoreLinkedSeaService = false,
        bool $ignoreDraftTimesheet = false,
        bool $ignoreAccommodation = false,
    ): void {
        $this->assertCanVoidMany(
            [$assignment],
            $companyId,
            $ignoreLinkedSeaService,
            $ignoreDraftTimesheet,
            $ignoreAccommodation,
        );
    }

    /**
     * Compute blockers for multiple assignments using grouped batch queries to eliminate N+1 overhead.
     *
     * @param  iterable<CrewAssignment>  $assignments
     * @return array<int, list<array{code: string, message: string}>>
     */
    public function batchBlockers(
        iterable $assignments,
        int $companyId,
        bool $ignoreLinkedSeaService = false,
        bool $ignoreDraftTimesheet = false,
        bool $ignoreAccommodation = false,
    ): array {
        $assignmentList = is_array($assignments) ? $assignments : iterator_to_array($assignments);

        if ($assignmentList === []) {
            return [];
        }

        $assignmentIds = [];
        $assignmentNos = [];
        foreach ($assignmentList as $assignment) {
            $id = (int) $assignment->id;
            $assignmentIds[] = $id;
            $assignmentNos[$id] = (string) $assignment->assignment_no;
        }
        $assignmentIds = array_values(array_unique($assignmentIds));

        // 1. Linked child assignments (transfer / redeploy chains)
        $linkedChildrenByParent = [];
        $linkedChildren = CrewAssignment::query()
            ->where('company_id', $companyId)
            ->whereIn('previous_assignment_id', $assignmentIds)
            ->get(['id', 'assignment_no', 'previous_assignment_id']);

        foreach ($linkedChildren as $child) {
            $parentId = (int) $child->previous_assignment_id;
            $linkedChildrenByParent[$parentId][] = [
                'id' => (int) $child->id,
                'assignment_no' => (string) $child->assignment_no,
            ];
        }

        // 2. Phases & Sea Service
        $phases = CrewAssignmentPhase::query()
            ->where('company_id', $companyId)
            ->whereIn('crew_assignment_id', $assignmentIds)
            ->get(['id', 'crew_assignment_id']);

        $phaseToAssignment = [];
        $phaseIds = [];
        foreach ($phases as $phase) {
            $pId = (int) $phase->id;
            $phaseIds[] = $pId;
            $phaseToAssignment[$pId] = (int) $phase->crew_assignment_id;
        }

        $assignmentsWithSeaService = [];
        if (! $ignoreLinkedSeaService && $phaseIds !== []) {
            $seaServicePhases = EmployeeSeaService::query()
                ->where('company_id', $companyId)
                ->whereIn('crew_assignment_phase_id', $phaseIds)
                ->pluck('crew_assignment_phase_id')
                ->all();

            foreach ($seaServicePhases as $phaseId) {
                $aId = $phaseToAssignment[(int) $phaseId] ?? null;
                if ($aId !== null) {
                    $assignmentsWithSeaService[$aId] = true;
                }
            }
        }

        // 3. Applied payroll preparation lines
        $assignmentsWithAppliedPayroll = CrewTimesheetPreparationLine::query()
            ->where('crew_timesheet_preparation_lines.company_id', $companyId)
            ->whereIn('crew_timesheet_preparation_lines.crew_assignment_id', $assignmentIds)
            ->whereHas('preparation', function ($query) use ($companyId): void {
                $query->where('company_id', $companyId)
                    ->where('status', CrewTimesheetPreparationStatus::Applied);
            })
            ->pluck('crew_assignment_id')
            ->map(fn ($id): int => (int) $id)
            ->flip()
            ->all();

        // 4. Protected payroll: submitted/approved preparation lines
        $assignmentsWithProtectedPrep = CrewTimesheetPreparationLine::query()
            ->where('crew_timesheet_preparation_lines.company_id', $companyId)
            ->whereIn('crew_timesheet_preparation_lines.crew_assignment_id', $assignmentIds)
            ->whereHas('preparation', function ($query) use ($companyId): void {
                $query->where('company_id', $companyId)
                    ->whereIn('status', [
                        CrewTimesheetPreparationStatus::Submitted,
                        CrewTimesheetPreparationStatus::Approved,
                    ]);
            })
            ->pluck('crew_assignment_id')
            ->map(fn ($id): int => (int) $id)
            ->flip()
            ->all();

        // 5. Protected payroll: timesheet segments on approved/paid/processing periods
        $protectedPeriodSegmentRows = CrewTimesheetSegment::query()
            ->where('crew_timesheet_segments.company_id', $companyId)
            ->whereIn('crew_timesheet_segments.crew_assignment_id', $assignmentIds)
            ->whereHas('timesheet.period', function ($query) use ($companyId): void {
                $query->where('company_id', $companyId)
                    ->whereIn('status', [
                        PayrollPeriodStatus::Approved,
                        PayrollPeriodStatus::Paid,
                        PayrollPeriodStatus::Processing,
                    ]);
            })
            ->join('crew_timesheets', 'crew_timesheets.id', '=', 'crew_timesheet_segments.crew_timesheet_id')
            ->join('payroll_periods', 'payroll_periods.id', '=', 'crew_timesheets.period_id')
            ->whereNull('crew_timesheet_segments.deleted_at')
            ->whereNull('crew_timesheets.deleted_at')
            ->get([
                'crew_timesheet_segments.crew_assignment_id',
                'payroll_periods.id as period_id',
                'payroll_periods.name as period_name',
                'payroll_periods.status as period_status',
            ]);

        $assignmentsWithProtectedPeriodSegments = [];
        $protectedPeriodMeta = [];
        foreach ($protectedPeriodSegmentRows as $row) {
            $aId = (int) $row->crew_assignment_id;
            $assignmentsWithProtectedPeriodSegments[$aId] = true;
            $protectedPeriodMeta[$aId] ??= [
                'period_id' => (int) $row->period_id,
                'period_name' => (string) $row->period_name,
                'period_status' => (string) $row->period_status,
            ];
        }

        // 6. Protected payroll: work allocations
        $assignmentsWithWorkAllocations = PayrollWorkAllocation::query()
            ->where('company_id', $companyId)
            ->whereIn('crew_assignment_id', $assignmentIds)
            ->whereIn('status', [
                PayrollWorkAllocationStatus::Approved,
                PayrollWorkAllocationStatus::Paid,
                PayrollWorkAllocationStatus::Reserved,
            ])
            ->pluck('crew_assignment_id')
            ->map(fn ($id): int => (int) $id)
            ->flip()
            ->all();

        // 7. Draft timesheet segments (cleanup-eligible when confirmed)
        $draftSegmentRows = [];
        if (! $ignoreDraftTimesheet) {
            $draftSegmentRows = CrewTimesheetSegment::query()
                ->where('crew_timesheet_segments.company_id', $companyId)
                ->whereIn('crew_timesheet_segments.crew_assignment_id', $assignmentIds)
                ->whereHas('timesheet.period', function ($query) use ($companyId): void {
                    $query->where('company_id', $companyId)
                        ->where('status', PayrollPeriodStatus::Draft);
                })
                ->join('crew_timesheets', 'crew_timesheets.id', '=', 'crew_timesheet_segments.crew_timesheet_id')
                ->join('payroll_periods', 'payroll_periods.id', '=', 'crew_timesheets.period_id')
                ->whereNull('crew_timesheet_segments.deleted_at')
                ->whereNull('crew_timesheets.deleted_at')
                ->get([
                    'crew_timesheet_segments.crew_assignment_id',
                    'crew_timesheet_segments.id as segment_id',
                    'payroll_periods.id as period_id',
                    'payroll_periods.name as period_name',
                    'payroll_periods.status as period_status',
                ]);
        }

        $draftTimesheetMeta = [];
        foreach ($draftSegmentRows as $row) {
            $aId = (int) $row->crew_assignment_id;
            $draftTimesheetMeta[$aId] ??= [
                'segment_ids' => [],
                'period_ids' => [],
                'periods' => [],
            ];
            $draftTimesheetMeta[$aId]['segment_ids'][(int) $row->segment_id] = true;
            $periodId = (int) $row->period_id;
            if (! isset($draftTimesheetMeta[$aId]['period_ids'][$periodId])) {
                $draftTimesheetMeta[$aId]['period_ids'][$periodId] = true;
                $draftTimesheetMeta[$aId]['periods'][] = [
                    'id' => $periodId,
                    'name' => (string) $row->period_name,
                    'status' => (string) $row->period_status,
                ];
            }
        }

        // Non-draft, non-protected period segments (e.g. Cancelled) remain hard blockers
        $assignmentsWithOtherSegments = [];
        if (! $ignoreDraftTimesheet) {
            $assignmentsWithOtherSegments = CrewTimesheetSegment::query()
                ->where('crew_timesheet_segments.company_id', $companyId)
                ->whereIn('crew_timesheet_segments.crew_assignment_id', $assignmentIds)
                ->whereHas('timesheet.period', function ($query) use ($companyId): void {
                    $query->where('company_id', $companyId)
                        ->whereNotIn('status', [
                            PayrollPeriodStatus::Draft,
                            PayrollPeriodStatus::Approved,
                            PayrollPeriodStatus::Paid,
                            PayrollPeriodStatus::Processing,
                        ]);
                })
                ->pluck('crew_assignment_id')
                ->map(fn ($id): int => (int) $id)
                ->flip()
                ->all();
        }

        // 8. Accommodation history (cleanup-eligible when confirmed)
        $accommodationMeta = [];
        if (! $ignoreAccommodation) {
            $accommodationRows = CrewAccommodationStay::query()
                ->where('crew_accommodation_stays.company_id', $companyId)
                ->whereIn('crew_accommodation_stays.crew_assignment_id', $assignmentIds)
                ->leftJoin('hotels', 'hotels.id', '=', 'crew_accommodation_stays.hotel_id')
                ->get([
                    'crew_accommodation_stays.id',
                    'crew_accommodation_stays.crew_assignment_id',
                    'crew_accommodation_stays.stay_type',
                    'crew_accommodation_stays.accommodation_status',
                    'crew_accommodation_stays.check_out_date',
                    'hotels.name as hotel_name',
                ]);

            foreach ($accommodationRows as $row) {
                $aId = (int) $row->crew_assignment_id;
                $accommodationMeta[$aId] ??= [
                    'count' => 0,
                    'open_count' => 0,
                    'summaries' => [],
                ];
                $status = $row->accommodation_status instanceof \BackedEnum
                    ? $row->accommodation_status->value
                    : (string) $row->accommodation_status;
                $stayType = $row->stay_type instanceof \BackedEnum
                    ? $row->stay_type->value
                    : (string) $row->stay_type;
                $isOpen = $row->check_out_date === null && $status === 'hotel';
                $accommodationMeta[$aId]['count']++;
                if ($isOpen) {
                    $accommodationMeta[$aId]['open_count']++;
                }
                $accommodationMeta[$aId]['summaries'][] = [
                    'id' => (int) $row->id,
                    'stay_type' => $stayType,
                    'accommodation_status' => $status,
                    'hotel_name' => $row->hotel_name !== null ? (string) $row->hotel_name : null,
                    'is_open' => $isOpen,
                ];
            }
        }

        $result = [];
        foreach ($assignmentList as $assignment) {
            $id = (int) $assignment->id;
            $assignmentNo = $assignmentNos[$id] ?? (string) $assignment->assignment_no;
            $blockers = [];

            if ((int) $assignment->company_id !== $companyId) {
                $blockers[] = [
                    'code' => 'cross_company',
                    'message' => 'Assignment does not belong to the active company.',
                ];
            }

            if ($assignment->voided_at !== null || $assignment->trashed()) {
                $blockers[] = [
                    'code' => 'already_voided',
                    'message' => 'This assignment has already been voided.',
                ];
            }

            if (isset($linkedChildrenByParent[$id])) {
                $dependentNos = array_map(
                    fn (array $child): string => $child['assignment_no'],
                    $linkedChildrenByParent[$id],
                );
                $dependentList = implode(', ', $dependentNos);
                $blockers[] = [
                    'code' => 'linked_assignment_exists',
                    'message' => count($dependentNos) === 1
                        ? "Cannot delete {$assignmentNo} because assignment {$dependentList} depends on it."
                        : "Cannot delete {$assignmentNo} because assignments {$dependentList} depend on it.",
                    'dependent_assignments' => $linkedChildrenByParent[$id],
                ];
            }

            if (! $ignoreLinkedSeaService && isset($assignmentsWithSeaService[$id])) {
                $blockers[] = [
                    'code' => 'sea_service_exists',
                    'message' => self::SEA_SERVICE_BLOCKED_MESSAGE,
                ];
            }

            if (isset($assignmentsWithAppliedPayroll[$id])) {
                $blockers[] = [
                    'code' => 'payroll_applied',
                    'message' => "Cannot delete {$assignmentNo}. ".self::PAYROLL_APPLIED_MESSAGE,
                ];
            }

            if (
                isset($assignmentsWithProtectedPrep[$id])
                || isset($assignmentsWithProtectedPeriodSegments[$id])
                || isset($assignmentsWithWorkAllocations[$id])
            ) {
                $meta = $protectedPeriodMeta[$id] ?? null;
                $periodSuffix = $meta !== null
                    ? " Payroll period \"{$meta['period_name']}\" is {$meta['period_status']}."
                    : '';
                $blockers[] = [
                    'code' => 'payroll_protected',
                    'message' => "Cannot delete {$assignmentNo}. ".self::PAYROLL_PROTECTED_MESSAGE.$periodSuffix,
                    'payroll_period' => $meta,
                ];
            }

            if (isset($draftTimesheetMeta[$id])) {
                $segmentCount = count($draftTimesheetMeta[$id]['segment_ids']);
                $periodCount = count($draftTimesheetMeta[$id]['period_ids']);
                $blockers[] = [
                    'code' => 'draft_timesheet_exists',
                    'message' => "Assignment {$assignmentNo} has Draft timesheet data ({$segmentCount} segment"
                        .($segmentCount === 1 ? '' : 's')
                        ." across {$periodCount} period"
                        .($periodCount === 1 ? '' : 's')
                        .'). Select "Remove linked Draft timesheet data" to continue.',
                    'draft_timesheet' => [
                        'segment_count' => $segmentCount,
                        'period_count' => $periodCount,
                        'periods' => $draftTimesheetMeta[$id]['periods'],
                    ],
                ];
            }

            if (isset($assignmentsWithOtherSegments[$id])) {
                $blockers[] = [
                    'code' => 'protected_dependency_exists',
                    'message' => "Cannot delete {$assignmentNo}. This assignment is linked to timesheet segments outside an editable Draft payroll period.",
                ];
            }

            if (isset($accommodationMeta[$id])) {
                $count = $accommodationMeta[$id]['count'];
                $openCount = $accommodationMeta[$id]['open_count'];
                $openSuffix = $openCount > 0
                    ? " {$openCount} stay".($openCount === 1 ? ' remains' : 's remain').' open.'
                    : '';
                $blockers[] = [
                    'code' => 'accommodation_history_exists',
                    'message' => "This assignment has {$count} accommodation record"
                        .($count === 1 ? '' : 's')
                        .".{$openSuffix} Select \"Delete linked accommodation records\" to continue.",
                    'accommodation' => [
                        'record_count' => $count,
                        'open_count' => $openCount,
                        'summaries' => $accommodationMeta[$id]['summaries'],
                    ],
                ];
            }

            $result[$id] = $this->uniqueByCode($blockers);
        }

        return $result;
    }

    /**
     * @param  iterable<CrewAssignment>  $assignments
     */
    public function assertCanVoidMany(
        iterable $assignments,
        int $companyId,
        bool $ignoreLinkedSeaService = false,
        bool $ignoreDraftTimesheet = false,
        bool $ignoreAccommodation = false,
    ): void {
        $batchBlockers = $this->batchBlockers(
            $assignments,
            $companyId,
            $ignoreLinkedSeaService,
            $ignoreDraftTimesheet,
            $ignoreAccommodation,
        );

        foreach ($assignments as $assignment) {
            $blockers = $batchBlockers[(int) $assignment->id] ?? [];
            if ($blockers !== []) {
                $this->throwBlockerValidationException($blockers);
            }
        }
    }

    /**
     * True blockers that cannot be cleared by cleanup checkboxes.
     *
     * @param  list<array{code: string, message: string}>  $blockers
     * @return list<array{code: string, message: string}>
     */
    public function protectedBlockers(array $blockers): array
    {
        return array_values(array_filter(
            $blockers,
            fn (array $blocker): bool => ! in_array($blocker['code'], self::CLEANUP_ELIGIBLE_CODES, true),
        ));
    }

    /**
     * @param  list<array{code: string, message: string}>  $blockers
     */
    private function throwBlockerValidationException(array $blockers): never
    {
        $priorityCodes = [
            'already_voided',
            'cross_company',
            'linked_assignment_exists',
            'payroll_applied',
            'payroll_protected',
            'protected_dependency_exists',
            'draft_timesheet_exists',
            'accommodation_history_exists',
            'sea_service_exists',
        ];

        $byCode = [];
        foreach ($blockers as $blocker) {
            $byCode[$blocker['code']] = $blocker['message'];
        }

        $message = self::BLOCKED_MESSAGE;
        foreach ($priorityCodes as $code) {
            if (isset($byCode[$code])) {
                $message = $byCode[$code];
                break;
            }
        }

        throw ValidationException::withMessages([
            'void' => $message,
        ]);
    }

    /**
     * @param  list<array{code: string, message: string}>  $blockers
     * @return list<array{code: string, message: string}>
     */
    private function uniqueByCode(array $blockers): array
    {
        $seen = [];
        $unique = [];

        foreach ($blockers as $blocker) {
            if (isset($seen[$blocker['code']])) {
                continue;
            }

            $seen[$blocker['code']] = true;
            $unique[] = $blocker;
        }

        return $unique;
    }
}
