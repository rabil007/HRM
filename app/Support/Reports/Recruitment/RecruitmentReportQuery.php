<?php

namespace App\Support\Reports\Recruitment;

use App\Enums\Recruitment\CandidateStage;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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
                SUM(CASE WHEN stage = ? THEN 1 ELSE 0 END) as selected_count,
                SUM(CASE WHEN stage = ? THEN 1 ELSE 0 END) as joining_count,
                SUM(CASE WHEN stage = ? THEN 1 ELSE 0 END) as joined_count,
                SUM(CASE WHEN stage = ? THEN 1 ELSE 0 END) as rejected_count,
                SUM(CASE WHEN employee_id IS NOT NULL THEN 1 ELSE 0 END) as converted_count
            ', [
                CandidateStage::OfferJol->value,
                CandidateStage::Joining->value,
                CandidateStage::Joined->value,
                CandidateStage::Rejected->value,
            ])
            ->first();

        // Calculate headcount information for active requirements matching the filter scope
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
        if ($requirementIds !== []) {
            $lineQuery = RecruitmentRequirementLine::query()
                ->whereIn('recruitment_requirement_id', $requirementIds);

            if ($this->filters->positionId !== null) {
                $lineQuery->where('position_id', $this->filters->positionId);
            }

            $headcountTotal = (int) $lineQuery->sum('required_headcount');
        }

        $confirmedJoined = (int) ($counts?->joined_count ?? 0);
        $remaining = max(0, $headcountTotal - $confirmedJoined);
        $overfill = max(0, $confirmedJoined - $headcountTotal);

        return [
            'applications_total' => (int) ($counts?->applications_total ?? 0),
            'selected_count' => (int) ($counts?->selected_count ?? 0),
            'joining_count' => (int) ($counts?->joining_count ?? 0),
            'joined_count' => $confirmedJoined,
            'rejected_count' => (int) ($counts?->rejected_count ?? 0),
            'converted_count' => (int) ($counts?->converted_count ?? 0),
            'headcount_total' => $headcountTotal,
            'headcount_confirmed_joined' => $confirmedJoined,
            'headcount_remaining' => $remaining,
            'headcount_overfill' => $overfill,
        ];
    }

    /**
     * Calendar days from candidate creation to confirmed joining date.
     * Returns null if candidate is not confirmed Joined.
     */
    public static function calculateCandidateToJoinedDays(RecruitmentCandidate $candidate, string $timezone): ?int
    {
        if ($candidate->joined_at === null || $candidate->created_at === null) {
            return null;
        }

        $createdDate = CarbonImmutable::parse($candidate->created_at)->timezone($timezone)->startOfDay();
        $joinedDate = CarbonImmutable::parse($candidate->joined_at)->timezone($timezone)->startOfDay();

        return (int) $createdDate->diffInDays($joinedDate, false);
    }

    /**
     * Calendar days from requirement approval to confirmed candidate joining date.
     * Returns null if requirement was not approved through approval workflow
     * or candidate is not confirmed Joined.
     */
    public static function calculateRequirementApprovalToJoinedDays(RecruitmentCandidate $candidate, string $timezone): ?int
    {
        if ($candidate->joined_at === null) {
            return null;
        }

        $approvedAt = $candidate->requirement?->approved_at;
        if ($approvedAt === null) {
            return null;
        }

        $approvalDate = CarbonImmutable::parse($approvedAt)->timezone($timezone)->startOfDay();
        $joinedDate = CarbonImmutable::parse($candidate->joined_at)->timezone($timezone)->startOfDay();

        return (int) $approvalDate->diffInDays($joinedDate, false);
    }
}
