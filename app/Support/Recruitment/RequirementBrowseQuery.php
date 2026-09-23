<?php

namespace App\Support\Recruitment;

use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class RequirementBrowseQuery
{
    /**
     * @return array{
     *     paginator: LengthAwarePaginator,
     *     tab_counts: array{active: int, on_hold: int, history: int},
     *     summary_cards: array{open_headcount: int, overdue: int, due_this_week: int, ready_to_close: int},
     *     current_tab: string,
     *     filters: array<string, mixed>,
     *     search: string
     * }
     */
    public static function get(Request $request, int $companyId): array
    {
        $today = Carbon::today();
        $currentTab = $request->query('tab', 'active');
        if (! in_array($currentTab, ['active', 'on_hold', 'history'], true)) {
            $currentTab = 'active';
        }

        $search = trim((string) $request->query('search', ''));
        $clientId = $request->query('client_id');
        $clientId = $clientId !== null && $clientId !== '' ? (int) $clientId : null;
        $projectId = $request->query('project_id');
        $projectId = $projectId !== null && $projectId !== '' ? (int) $projectId : null;
        $positionId = $request->query('position_id');
        $positionId = $positionId !== null && $positionId !== '' ? (int) $positionId : null;
        $assignedTo = $request->query('assigned_to');
        $assignedTo = $assignedTo !== null && $assignedTo !== '' ? (int) $assignedTo : null;
        $priority = $request->query('priority');
        $priority = $priority !== null && $priority !== '' ? (string) $priority : null;
        $deadlineHealth = $request->query('deadline_health');
        $deadlineHealth = $deadlineHealth !== null && $deadlineHealth !== '' ? (string) $deadlineHealth : null;

        // Base builder for list query
        $query = RecruitmentRequirement::query()
            ->where('company_id', $companyId)
            ->with([
                'client:id,name',
                'project:id,title',
                'assignedRecruiter:id,name',
                'repeatedFrom:id,requirement_number',
                'lines.position:id,title',
            ]);

        // Tab scoping
        match ($currentTab) {
            'on_hold' => $query->where('status', RequirementStatus::OnHold),
            'history' => $query->whereIn('status', [RequirementStatus::Completed, RequirementStatus::Cancelled]),
            default => $query->whereIn('status', [RequirementStatus::Draft, RequirementStatus::Open]),
        };

        // Search
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('requirement_number', 'like', "%{$search}%")
                    ->orWhere('client_reference_number', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
                    ->orWhereHas('client', fn (Builder $c) => $c->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('project', fn (Builder $p) => $p->where('title', 'like', "%{$search}%"))
                    ->orWhereHas('lines.position', fn (Builder $pos) => $pos->where('title', 'like', "%{$search}%"));
            });
        }

        // Filters
        if ($clientId !== null) {
            $query->where('client_id', $clientId);
        }

        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        if ($positionId !== null) {
            $query->whereHas('lines', fn (Builder $l) => $l->where('position_id', $positionId));
        }

        if ($assignedTo !== null) {
            $query->where('assigned_to', $assignedTo);
        }

        if ($priority !== null) {
            $query->where('priority', $priority);
        }

        if ($deadlineHealth !== null) {
            if ($deadlineHealth === 'overdue') {
                $query->where('required_by_date', '<', $today->toDateString());
            } elseif ($deadlineHealth === 'due_soon') {
                $query->where('required_by_date', '>=', $today->toDateString())
                    ->where('required_by_date', '<', $today->copy()->addDays(8)->toDateString());
            } elseif ($deadlineHealth === 'on_track') {
                $query->where('required_by_date', '>=', $today->copy()->addDays(8)->toDateString());
            }
        }

        $perPage = (int) $request->query('per_page', 15);
        if ($perPage < 5 || $perPage > 100) {
            $perPage = 15;
        }

        $paginator = $query->latest('id')->paginate($perPage)->withQueryString();

        // Tab counts (company-wide, not filter-constrained)
        $tabCounts = [
            'active' => RecruitmentRequirement::query()
                ->where('company_id', $companyId)
                ->whereIn('status', [RequirementStatus::Draft, RequirementStatus::Open])
                ->count(),
            'on_hold' => RecruitmentRequirement::query()
                ->where('company_id', $companyId)
                ->where('status', RequirementStatus::OnHold)
                ->count(),
            'history' => RecruitmentRequirement::query()
                ->where('company_id', $companyId)
                ->whereIn('status', [RequirementStatus::Completed, RequirementStatus::Cancelled])
                ->count(),
        ];

        // Company-wide active overview, matching the unfiltered active tab.
        $openHeadcount = (int) RecruitmentRequirementLine::query()
            ->where('company_id', $companyId)
            ->whereHas('requirement', function (Builder $r): void {
                $r->whereIn('status', [RequirementStatus::Draft, RequirementStatus::Open]);
            })
            ->sum('required_headcount');

        $activeQuery = RecruitmentRequirement::query()
            ->where('company_id', $companyId)
            ->whereIn('status', [RequirementStatus::Draft, RequirementStatus::Open]);

        $overdueCount = (clone $activeQuery)
            ->where('required_by_date', '<', $today->toDateString())
            ->count();

        $dueThisWeekCount = (clone $activeQuery)
            ->where('required_by_date', '>=', $today->toDateString())
            ->where('required_by_date', '<', $today->copy()->addDays(8)->toDateString())
            ->count();

        $summaryCards = [
            'open_headcount' => $openHeadcount,
            'overdue' => $overdueCount,
            'due_this_week' => $dueThisWeekCount,
            'ready_to_close' => 0, // Extension point for Phase 2 Candidates
        ];

        return [
            'paginator' => $paginator,
            'tab_counts' => $tabCounts,
            'summary_cards' => $summaryCards,
            'current_tab' => $currentTab,
            'filters' => [
                'tab' => $currentTab,
                'search' => $search,
                'per_page' => $perPage,
                'client_id' => $clientId,
                'project_id' => $projectId,
                'position_id' => $positionId,
                'assigned_to' => $assignedTo,
                'priority' => $priority,
                'deadline_health' => $deadlineHealth,
            ],
            'search' => $search,
        ];
    }
}
