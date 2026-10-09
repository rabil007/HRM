<?php

namespace App\Support\CrewMovements\Scheduling;

use App\Enums\CrewScheduledMovementStatus;
use App\Models\CrewScheduledMovement;
use App\Models\User;
use App\Support\Settings\CompanyTimezone;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class CrewScheduledMovementIndexQuery
{
    /**
     * @param  array{
     *     status?: string|null,
     *     movement_action?: string|null,
     *     employee_id?: int|null,
     *     vessel_id?: int|null,
     *     search?: string|null,
     *     scheduled_from?: string|null,
     *     scheduled_to?: string|null,
     *     tab?: string|null
     * }  $filters
     * @return array{
     *     items: list<array<string, mixed>>,
     *     pagination: array{current_page: int, last_page: int, per_page: int, total: int},
     *     filters: array<string, mixed>,
     *     counts: array{upcoming: int, due: int, needs_attention: int, executed: int, cancelled: int}
     * }
     */
    public function paginate(int $companyId, ?User $viewer, array $filters = [], int $perPage = 20): array
    {
        $timezone = CompanyTimezone::forCompanyId($companyId);
        $now = Carbon::now('UTC');

        $base = CrewScheduledMovementAccess::applyScope(
            CrewScheduledMovement::query(),
            $companyId,
            $viewer,
        );

        $counts = [
            'upcoming' => (clone $base)->where('status', CrewScheduledMovementStatus::Scheduled)
                ->where('scheduled_at', '>', $now)->count(),
            'due' => (clone $base)->where('status', CrewScheduledMovementStatus::Scheduled)
                ->where('scheduled_at', '<=', $now)->count(),
            'needs_attention' => (clone $base)->where('status', CrewScheduledMovementStatus::NeedsAttention)->count(),
            'executed' => (clone $base)->where('status', CrewScheduledMovementStatus::Executed)->count(),
            'cancelled' => (clone $base)->where('status', CrewScheduledMovementStatus::Cancelled)->count(),
        ];

        $query = CrewScheduledMovementAccess::applyScope(
            CrewScheduledMovement::query(),
            $companyId,
            $viewer,
        )->with([
            'employee:id,name,employee_no',
            'assignment:id,assignment_no,vessel_id',
            'assignment.vessel:id,name',
            'creator:id,name',
            'updater:id,name',
            'canceller:id,name',
        ]);

        $this->applyFilters($query, $filters, $timezone, $now);

        /** @var LengthAwarePaginator<int, CrewScheduledMovement> $paginator */
        $paginator = $query
            ->orderByRaw("CASE status
                WHEN 'needs_attention' THEN 0
                WHEN 'processing' THEN 1
                WHEN 'scheduled' THEN 2
                WHEN 'executed' THEN 3
                ELSE 4 END")
            ->orderBy('scheduled_at')
            ->paginate($perPage)
            ->withQueryString();

        $presenter = new CrewScheduledMovementPresenter;

        return [
            'items' => $presenter->listItems(collect($paginator->items()), $timezone, $viewer),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'filters' => [
                'status' => $filters['status'] ?? null,
                'movement_action' => $filters['movement_action'] ?? null,
                'employee_id' => $filters['employee_id'] ?? null,
                'vessel_id' => $filters['vessel_id'] ?? null,
                'search' => $filters['search'] ?? null,
                'scheduled_from' => $filters['scheduled_from'] ?? null,
                'scheduled_to' => $filters['scheduled_to'] ?? null,
                'tab' => $filters['tab'] ?? 'upcoming',
            ],
            'counts' => $counts,
        ];
    }

    /**
     * @param  Builder<CrewScheduledMovement>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters, string $timezone, Carbon $now): void
    {
        $tab = (string) ($filters['tab'] ?? 'upcoming');

        match ($tab) {
            'due' => $query->where('status', CrewScheduledMovementStatus::Scheduled)
                ->where('scheduled_at', '<=', $now),
            'needs_attention' => $query->where('status', CrewScheduledMovementStatus::NeedsAttention),
            'executed' => $query->where('status', CrewScheduledMovementStatus::Executed),
            'cancelled' => $query->where('status', CrewScheduledMovementStatus::Cancelled),
            'all' => null,
            default => $query->where('status', CrewScheduledMovementStatus::Scheduled)
                ->where('scheduled_at', '>', $now),
        };

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['movement_action'])) {
            $query->where('movement_action', $filters['movement_action']);
        }

        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', (int) $filters['employee_id']);
        }

        if (! empty($filters['vessel_id'])) {
            $query->whereHas('assignment', fn (Builder $q) => $q->where('vessel_id', (int) $filters['vessel_id']));
        }

        if (! empty($filters['scheduled_from'])) {
            $from = Carbon::parse((string) $filters['scheduled_from'], $timezone)->startOfDay()->utc();
            $query->where('scheduled_at', '>=', $from);
        }

        if (! empty($filters['scheduled_to'])) {
            $to = Carbon::parse((string) $filters['scheduled_to'], $timezone)->endOfDay()->utc();
            $query->where('scheduled_at', '<=', $to);
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $q) use ($search): void {
                $q->whereHas('employee', function (Builder $employee) use ($search): void {
                    $employee->where('name', 'like', "%{$search}%")
                        ->orWhere('employee_no', 'like', "%{$search}%");
                })->orWhereHas('assignment', function (Builder $assignment) use ($search): void {
                    $assignment->where('assignment_no', 'like', "%{$search}%");
                });
            });
        }
    }
}
