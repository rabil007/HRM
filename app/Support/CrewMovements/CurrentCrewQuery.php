<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewAssignmentStatus;
use App\Models\Client;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\Vessel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class CurrentCrewQuery
{
    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 100;

    /**
     * @param  array<string, mixed>  $filters
     */
    public static function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        $query = CrewAssignment::query()
            ->where('company_id', $companyId);

        $includeCompleted = filter_var($filters['include_completed'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $statusFilter = $filters['status'] ?? null;

        if ($statusFilter !== null && $statusFilter !== '') {
            $query->where('status', CrewAssignmentStatus::tryFrom((string) $statusFilter));
        } elseif (! $includeCompleted) {
            $query->whereIn('status', [CrewAssignmentStatus::Draft, CrewAssignmentStatus::Active]);
        }

        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function (Builder $q) use ($search) {
                $q->where('assignment_no', 'like', '%'.$search.'%')
                    ->orWhereHas('employee', fn (Builder $e) => $e->where('name', 'like', '%'.$search.'%')
                        ->orWhere('employee_no', 'like', '%'.$search.'%'))
                    ->orWhereHas('vessel', fn (Builder $v) => $v->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('rank', fn (Builder $r) => $r->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('client', fn (Builder $c) => $c->where('name', 'like', '%'.$search.'%'));
            });
        }

        if (! empty($filters['phase'])) {
            $phase = (string) $filters['phase'];
            $query->whereHas('currentPhase', fn (Builder $p) => $p->where('phase_code', $phase));
        }

        if (! empty($filters['vessel_id'])) {
            $query->where('vessel_id', (int) $filters['vessel_id']);
        }

        if (! empty($filters['rank_id'])) {
            $query->where('rank_id', (int) $filters['rank_id']);
        }

        if (! empty($filters['client_id'])) {
            $query->where('client_id', (int) $filters['client_id']);
        }

        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', (int) $filters['employee_id']);
        }

        if (! empty($filters['planned_join_from'])) {
            $query->where('planned_join_at', '>=', $filters['planned_join_from']);
        }

        if (! empty($filters['planned_join_to'])) {
            $query->where('planned_join_at', '<=', $filters['planned_join_to']);
        }

        if (! empty($filters['planned_signoff_from'])) {
            $query->where('planned_signoff_at', '>=', $filters['planned_signoff_from']);
        }

        if (! empty($filters['planned_signoff_to'])) {
            $query->where('planned_signoff_at', '<=', $filters['planned_signoff_to']);
        }

        if (! empty($filters['movement_attention'])) {
            $query->with(['currentPhase', 'company']);
        }

        $sort = $filters['sort'] ?? 'created_at';
        $direction = in_array($filters['direction'] ?? 'desc', ['asc', 'desc'], true)
            ? $filters['direction']
            : 'desc';

        $allowedSorts = [
            'created_at',
            'assignment_no',
            'planned_join_at',
            'planned_signoff_at',
            'status',
        ];

        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'created_at';
        }

        $query->orderBy($sort, $direction);

        $query->with([
            'employee',
            'rank',
            'vessel',
            'client',
            'currentPhase',
            'phases',
            'companyVisaType',
            'planningAssignment',
            'company',
        ]);

        $perPage = self::resolvePerPage($filters['per_page'] ?? null);

        $paginator = $query->paginate($perPage)->withQueryString();

        if (! empty($filters['movement_attention'])) {
            $paginator->getCollection()->transform(function (CrewAssignment $assignment) {
                $assignment->attention_warnings = CrewMovementAttentionQuery::forAssignment($assignment);

                return $assignment;
            });

            $filtered = $paginator->getCollection()->filter(fn (CrewAssignment $a) => $a->attention_warnings !== []);
            $paginator->setCollection($filtered->values());
        }

        return $paginator;
    }

    /**
     * @return array{
     *     vessels: list<array{id: int, name: string}>,
     *     ranks: list<array{id: int, name: string}>,
     *     clients: list<array{id: int, name: string}>,
     *     employees: list<array{id: int, name: string, employee_no: string|null}>
     * }
     */
    public static function filterOptions(int $companyId): array
    {
        return [
            'vessels' => Vessel::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Vessel $v) => ['id' => $v->id, 'name' => $v->name])
                ->values()
                ->all(),
            'ranks' => Rank::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Rank $r) => ['id' => $r->id, 'name' => $r->name])
                ->values()
                ->all(),
            'clients' => Client::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Client $c) => ['id' => $c->id, 'name' => $c->name])
                ->values()
                ->all(),
            'employees' => Employee::query()
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'employee_no'])
                ->map(fn (Employee $e) => [
                    'id' => $e->id,
                    'name' => $e->name,
                    'employee_no' => $e->employee_no,
                ])
                ->values()
                ->all(),
        ];
    }

    private static function resolvePerPage(?string $value): int
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_PER_PAGE;
        }

        $perPage = (int) $value;

        if ($perPage < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }
}
