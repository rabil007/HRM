<?php

namespace App\Support\Recruitment;

use App\Enums\Recruitment\RequirementHeadcountRevisionInitiator;
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
        $needsAction = $request->query('needs_action');
        $needsAction = $needsAction !== null && $needsAction !== '' ? (string) $needsAction : null;

        // Base builder for list query
        $query = RecruitmentRequirement::query()
            ->where('company_id', $companyId)
            ->with(RequirementSubmissionReadinessLookup::eagerLoad());

        if (in_array($needsAction, ['deadline_extension', 'headcount_revision'], true)) {
            self::applyNeedsActionScope($query, $request);
        } else {
            match ($currentTab) {
                'on_hold' => $query->where('status', RequirementStatus::OnHold),
                'history' => $query->whereIn('status', RequirementStatus::historyListStatuses()),
                default => $query->whereIn('status', RequirementStatus::activeListStatuses()),
            };
        }

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
                ->whereIn('status', RequirementStatus::activeListStatuses())
                ->count(),
            'on_hold' => RecruitmentRequirement::query()
                ->where('company_id', $companyId)
                ->where('status', RequirementStatus::OnHold)
                ->count(),
            'history' => RecruitmentRequirement::query()
                ->where('company_id', $companyId)
                ->whereIn('status', RequirementStatus::historyListStatuses())
                ->count(),
        ];

        // Company-wide active overview, matching the unfiltered active tab.
        $openHeadcount = (int) RecruitmentRequirementLine::query()
            ->where('company_id', $companyId)
            ->whereHas('requirement', function (Builder $r): void {
                $r->whereIn('status', RequirementStatus::activeListStatuses());
            })
            ->sum('required_headcount');

        $activeQuery = RecruitmentRequirement::query()
            ->where('company_id', $companyId)
            ->whereIn('status', RequirementStatus::activeListStatuses());

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
                'needs_action' => $needsAction,
            ],
            'search' => $search,
        ];
    }

    /**
     * Actionable approvals across Open and On Hold.
     * Normal Active / On Hold / History tabs stay unchanged.
     */
    private static function applyNeedsActionScope(Builder $query, Request $request): void
    {
        $actorId = $request->user()?->id;

        if ($actorId === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $actorId = (int) $actorId;

        $query->whereIn('status', [RequirementStatus::Open, RequirementStatus::OnHold])
            ->where(function (Builder $actionable) use ($actorId): void {
                $actionable->where(function (Builder $deadline) use ($actorId): void {
                    $deadline->where('created_by', $actorId)
                        ->whereHas('pendingDeadlineExtension');
                })->orWhere(function (Builder $asRequester) use ($actorId): void {
                    $asRequester->where('created_by', $actorId)
                        ->whereHas('pendingHeadcountRevision', function (Builder $revision): void {
                            $revision->where('initiator', RequirementHeadcountRevisionInitiator::Recruiter);
                        });
                })->orWhere(function (Builder $asRecruiter) use ($actorId): void {
                    $asRecruiter->where('assigned_to', $actorId)
                        ->whereColumn('assigned_to', '!=', 'created_by')
                        ->whereHas('pendingHeadcountRevision', function (Builder $revision) use ($actorId): void {
                            $revision->where('initiator', RequirementHeadcountRevisionInitiator::Requester)
                                ->where('requested_by', '!=', $actorId);
                        });
                });
            });
    }
}
