<?php

namespace App\Support\Reports\Recruitment;

use App\Enums\Recruitment\CandidateInterviewOutcome;
use App\Enums\Recruitment\CandidateStage;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class RecruitmentReportQuery
{
    public function __construct(
        public readonly int $companyId,
        public readonly RecruitmentReportFilters $filters,
        public readonly string $timezone,
        public readonly ?User $user = null,
    ) {}

    /**
     * @return Builder<RecruitmentCandidate>
     */
    public function baseQuery(): Builder
    {
        $query = RecruitmentCandidate::query()
            ->where('recruitment_candidates.company_id', $this->companyId)
            ->with([
                'requirement.client:id,name',
                'requirement.project:id,title',
                'requirement.assignedRecruiter:id,name',
                'line.position:id,title',
                'nationality:id,name',
                'employee:id,name,employee_no,company_id,department_id',
            ]);

        $this->applyFilters($query);

        return $query;
    }

    /**
     * @param  Builder<RecruitmentCandidate>  $query
     */
    public function applyFilters(Builder $query): void
    {
        $filters = $this->filters;

        if ($filters->requirementId !== null) {
            $query->where('recruitment_candidates.recruitment_requirement_id', $filters->requirementId);
        }

        if ($filters->clientId !== null) {
            $query->whereHas('requirement', function (Builder $q) use ($filters): void {
                $q->where('client_id', $filters->clientId);
            });
        }

        if ($filters->projectId !== null) {
            $query->whereHas('requirement', function (Builder $q) use ($filters): void {
                $q->where('project_id', $filters->projectId);
            });
        }

        if ($filters->positionId !== null) {
            $query->whereHas('line', function (Builder $q) use ($filters): void {
                $q->where('position_id', $filters->positionId);
            });
        }

        if ($filters->recruiterId !== null) {
            $query->whereHas('requirement', function (Builder $q) use ($filters): void {
                $q->where('assigned_to', $filters->recruiterId);
            });
        }

        if ($filters->stage !== null) {
            $query->where('recruitment_candidates.stage', $filters->stage);
        }

        if ($filters->joiningDateFrom !== null || $filters->joiningDateTo !== null) {
            $query->where(function (Builder $q) use ($filters): void {
                // If actual_joining_date is present, use it; otherwise fallback to expected_joining_date
                $dateCol = DB::raw('COALESCE(recruitment_candidates.actual_joining_date, recruitment_candidates.expected_joining_date)');

                if ($filters->joiningDateFrom !== null && $filters->joiningDateTo !== null) {
                    $q->whereBetween($dateCol, [$filters->joiningDateFrom, $filters->joiningDateTo]);
                } elseif ($filters->joiningDateFrom !== null) {
                    $q->where($dateCol, '>=', $filters->joiningDateFrom);
                } elseif ($filters->joiningDateTo !== null) {
                    $q->where($dateCol, '<=', $filters->joiningDateTo);
                }
            });
        }

        if ($filters->conversionStatus !== null && $filters->conversionStatus !== 'all') {
            if ($filters->conversionStatus === 'converted') {
                $query->whereNotNull('recruitment_candidates.employee_id');
            } elseif ($filters->conversionStatus === 'pending') {
                $query->where('recruitment_candidates.stage', CandidateStage::Joined->value)
                    ->whereNull('recruitment_candidates.employee_id');
            } elseif ($filters->conversionStatus === 'unconverted') {
                $query->where(function (Builder $q): void {
                    $q->where('recruitment_candidates.stage', '!=', CandidateStage::Joined->value)
                        ->orWhereNull('recruitment_candidates.employee_id');
                });
            }
        }

        if ($filters->search !== null && $filters->search !== '') {
            $search = '%'.$filters->search.'%';
            $query->where(function (Builder $q) use ($search): void {
                $q->where('recruitment_candidates.name', 'like', $search)
                    ->orWhere('recruitment_candidates.email', 'like', $search)
                    ->orWhere('recruitment_candidates.phone', 'like', $search)
                    ->orWhere('recruitment_candidates.requirement_number_snapshot', 'like', $search)
                    ->orWhere('recruitment_candidates.position_title_snapshot', 'like', $search);
            });
        }
    }

    public function paginate(int $perPage): LengthAwarePaginator
    {
        $query = $this->baseQuery();

        $sort = $this->filters->sort;
        $direction = $this->filters->direction;

        $query->orderBy("recruitment_candidates.{$sort}", $direction);
        if ($sort !== 'id') {
            $query->orderBy('recruitment_candidates.id', 'desc');
        }

        return $query->paginate($perPage);
    }

    /**
     * @return Builder<RecruitmentCandidate>
     */
    public function exportQuery(): Builder
    {
        $query = $this->baseQuery();
        $sort = $this->filters->sort;
        $direction = $this->filters->direction;

        $query->orderBy("recruitment_candidates.{$sort}", $direction);
        if ($sort !== 'id') {
            $query->orderBy('recruitment_candidates.id', 'desc');
        }

        return $query;
    }

    /**
     * Grouped pipeline metrics and requirement headcount summary.
     *
     * @return array{
     *     applications_total: int,
     *     selected_count: int,
     *     joining_count: int,
     *     joined_count: int,
     *     rejected_count: int,
     *     converted_count: int,
     *     headcount_total: int,
     *     headcount_confirmed_joined: int,
     *     headcount_remaining: int,
     *     headcount_overfill: int,
     * }
     */
    public function summary(): array
    {
        $base = RecruitmentCandidate::query()
            ->where('recruitment_candidates.company_id', $this->companyId);

        $this->applyFilters($base);

        $counts = (clone $base)
            ->selectRaw('
                COUNT(*) as applications_total,
                SUM(CASE WHEN recruitment_candidates.interview_outcome = ? THEN 1 ELSE 0 END) as selected_count,
                SUM(CASE WHEN recruitment_candidates.stage = ? THEN 1 ELSE 0 END) as joining_count,
                SUM(CASE WHEN recruitment_candidates.stage = ? THEN 1 ELSE 0 END) as joined_count,
                SUM(CASE WHEN recruitment_candidates.stage = ? THEN 1 ELSE 0 END) as rejected_count,
                SUM(CASE WHEN recruitment_candidates.employee_id IS NOT NULL THEN 1 ELSE 0 END) as converted_count
            ', [
                CandidateInterviewOutcome::Selected->value,
                CandidateStage::Joining->value,
                CandidateStage::Joined->value,
                CandidateStage::Rejected->value,
            ])
            ->first();

        // Calculate position fulfillment totals derived from all confirmed Joined candidates
        // for the scoped requirements and position lines, unaffected by candidate-stage/search/conversion/date filters.
        $requirementsQuery = RecruitmentRequirement::query()
            ->where('company_id', $this->companyId);

        if ($this->filters->requirementId !== null) {
            $requirementsQuery->whereKey($this->filters->requirementId);
        }
        if ($this->filters->clientId !== null) {
            $requirementsQuery->where('client_id', $this->filters->clientId);
        }
        if ($this->filters->projectId !== null) {
            $requirementsQuery->where('project_id', $this->filters->projectId);
        }
        if ($this->filters->recruiterId !== null) {
            $requirementsQuery->where('assigned_to', $this->filters->recruiterId);
        }

        $requirementIds = $requirementsQuery->pluck('id')->all();

        $headcountTotal = 0;
        $confirmedJoinedTotal = 0;
        $remainingTotal = 0;
        $overfillTotal = 0;

        if ($requirementIds !== []) {
            $lineQuery = RecruitmentRequirementLine::query()
                ->whereIn('recruitment_requirement_id', $requirementIds);

            if ($this->filters->positionId !== null) {
                $lineQuery->where('position_id', $this->filters->positionId);
            }

            /** @var Collection<int, RecruitmentRequirementLine> $lines */
            $lines = $lineQuery->get(['id', 'required_headcount']);
            $lineIds = $lines->pluck('id')->all();

            $joinedPerLine = RecruitmentCandidate::query()
                ->where('company_id', $this->companyId)
                ->whereIn('recruitment_requirement_line_id', $lineIds)
                ->where('stage', CandidateStage::Joined->value)
                ->selectRaw('recruitment_requirement_line_id, COUNT(*) as aggregate')
                ->groupBy('recruitment_requirement_line_id')
                ->pluck('aggregate', 'recruitment_requirement_line_id');

            foreach ($lines as $line) {
                $required = (int) $line->required_headcount;
                $joined = (int) ($joinedPerLine[$line->id] ?? 0);

                $headcountTotal += $required;
                $confirmedJoinedTotal += $joined;
                $remainingTotal += max(0, $required - $joined);
                $overfillTotal += max(0, $joined - $required);
            }
        }

        return [
            'applications_total' => (int) ($counts?->applications_total ?? 0),
            'selected_count' => (int) ($counts?->selected_count ?? 0),
            'joining_count' => (int) ($counts?->joining_count ?? 0),
            'joined_count' => (int) ($counts?->joined_count ?? 0),
            'rejected_count' => (int) ($counts?->rejected_count ?? 0),
            'converted_count' => (int) ($counts?->converted_count ?? 0),
            'headcount_total' => $headcountTotal,
            'headcount_confirmed_joined' => $confirmedJoinedTotal,
            'headcount_remaining' => $remainingTotal,
            'headcount_overfill' => $overfillTotal,
        ];
    }

    /**
     * Calendar days from candidate creation to confirmed actual joining date.
     * Uses actual_joining_date in company timezone; joined_at serves as audit confirmation time.
     * Returns null if candidate is not confirmed Joined or authoritative dates are missing/chronologically invalid.
     */
    public static function calculateCandidateToJoinedDays(RecruitmentCandidate $candidate, string $timezone): ?int
    {
        if ($candidate->stage !== CandidateStage::Joined || $candidate->actual_joining_date === null || $candidate->created_at === null) {
            return null;
        }

        $joiningDateString = $candidate->actual_joining_date instanceof \DateTimeInterface
            ? $candidate->actual_joining_date->format('Y-m-d')
            : (is_string($candidate->actual_joining_date) ? substr($candidate->actual_joining_date, 0, 10) : null);

        if ($joiningDateString === null) {
            return null;
        }

        $createdDate = CarbonImmutable::parse($candidate->created_at)->timezone($timezone)->startOfDay();
        $joinedDate = CarbonImmutable::createFromFormat('!Y-m-d', $joiningDateString, $timezone)->startOfDay();

        $days = (int) $createdDate->diffInDays($joinedDate, false);

        if ($days < 0) {
            return null;
        }

        return $days;
    }

    /**
     * Calendar days from requirement approval to confirmed candidate actual joining date.
     * Uses actual_joining_date in company timezone.
     * Returns null if requirement was not approved, candidate is not confirmed Joined,
     * or dates are missing/chronologically invalid.
     */
    public static function calculateRequirementApprovalToJoinedDays(RecruitmentCandidate $candidate, string $timezone): ?int
    {
        if ($candidate->stage !== CandidateStage::Joined || $candidate->actual_joining_date === null) {
            return null;
        }

        $approvedAt = $candidate->requirement?->approved_at;
        if ($approvedAt === null) {
            return null;
        }

        $joiningDateString = $candidate->actual_joining_date instanceof \DateTimeInterface
            ? $candidate->actual_joining_date->format('Y-m-d')
            : (is_string($candidate->actual_joining_date) ? substr($candidate->actual_joining_date, 0, 10) : null);

        if ($joiningDateString === null) {
            return null;
        }

        $approvalDate = CarbonImmutable::parse($approvedAt)->timezone($timezone)->startOfDay();
        $joinedDate = CarbonImmutable::createFromFormat('!Y-m-d', $joiningDateString, $timezone)->startOfDay();

        $days = (int) $approvalDate->diffInDays($joinedDate, false);

        if ($days < 0) {
            return null;
        }

        return $days;
    }
}
