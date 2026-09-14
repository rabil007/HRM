<?php

namespace App\Support\Activity;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

final class ActivityLogQuery
{
    /**
     * @return array{
     *     filters: array{q: string, event: string, module: string, user_id: string, importance: string, date_from: string, date_to: string},
     *     paginator: LengthAwarePaginator<int, Activity>,
     *     modules: list<array{key: string, label: string}>,
     *     users: list<array{id: int, name: string, email: string}>,
     *     summary: array{total: int, users: int, important: int, critical: int}
     * }
     */
    public function for(Request $request, int $companyId, int $perPage): array
    {
        $filters = $this->filters($request);
        $allSubjectTypes = $this->subjectTypes($companyId);
        $query = $this->baseQuery($companyId)
            ->whereDate('created_at', '>=', $filters['date_from'])
            ->whereDate('created_at', '<=', $filters['date_to']);

        if ($filters['event'] !== '') {
            $query->where('event', $filters['event']);
        }

        if ($filters['user_id'] !== '' && ctype_digit($filters['user_id'])) {
            $query->where('causer_id', (int) $filters['user_id']);
        }

        if ($filters['module'] !== '') {
            $types = ActivityLogIntelligence::typesForModule($allSubjectTypes, $filters['module']);
            $query->whereIn('subject_type', $types === [] ? ['__no_matching_subject_type__'] : $types);
        }

        if ($filters['importance'] !== '') {
            $types = ActivityLogIntelligence::typesForImportance($allSubjectTypes, $filters['importance']);
            $query->whereIn('subject_type', $types === [] ? ['__no_matching_subject_type__'] : $types);
        }

        if ($filters['q'] !== '') {
            $search = $filters['q'];

            $query->where(function (Builder $sub) use ($search): void {
                $sub
                    ->where('description', 'like', '%'.$search.'%')
                    ->orWhere('subject_type', 'like', '%'.$search.'%')
                    ->orWhere('attribute_changes', 'like', '%'.$search.'%')
                    ->orWhere('properties', 'like', '%'.$search.'%')
                    ->orWhereHas('causer', function (Builder $user) use ($search): void {
                        $user
                            ->where('name', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%');
                    });
            });
        }

        $summaryQuery = clone $query;
        $summaryRows = (clone $summaryQuery)
            ->selectRaw('subject_type, COUNT(*) as aggregate')
            ->groupBy('subject_type')
            ->get();

        $summary = [
            'total' => (clone $summaryQuery)->count(),
            'users' => (clone $summaryQuery)->distinct('causer_id')->count('causer_id'),
            'important' => $summaryRows
                ->filter(fn (Activity $row): bool => ActivityLogIntelligence::importanceForType($row->subject_type) === 'important')
                ->sum(fn (Activity $row): int => (int) $row->getAttribute('aggregate')),
            'critical' => $summaryRows
                ->filter(fn (Activity $row): bool => ActivityLogIntelligence::importanceForType($row->subject_type) === 'critical')
                ->sum(fn (Activity $row): int => (int) $row->getAttribute('aggregate')),
        ];

        $paginator = $query
            ->with(['causer:id,name,email', 'subject'])
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        $userIds = $this->baseQuery($companyId)
            ->distinct()
            ->pluck('causer_id')
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->values();

        $users = User::query()
            ->whereIn('id', $userIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user): array => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
            ])
            ->values()
            ->all();

        return [
            'filters' => $filters,
            'paginator' => $paginator,
            'modules' => ActivityLogIntelligence::moduleOptions($allSubjectTypes),
            'users' => $users,
            'summary' => $summary,
        ];
    }

    /**
     * @return array{q: string, event: string, module: string, user_id: string, importance: string, date_from: string, date_to: string}
     */
    private function filters(Request $request): array
    {
        $today = CarbonImmutable::today();
        $dateFrom = $request->string('date_from')->toString();
        $dateTo = $request->string('date_to')->toString();

        return [
            'q' => trim($request->string('q')->toString()),
            'event' => trim($request->string('event')->toString()),
            'module' => trim($request->string('module')->toString()),
            'user_id' => trim($request->string('user_id')->toString()),
            'importance' => trim($request->string('importance')->toString()),
            'date_from' => $dateFrom !== '' ? $dateFrom : $today->toDateString(),
            'date_to' => $dateTo !== '' ? $dateTo : $today->toDateString(),
        ];
    }

    /**
     * @return Builder<Activity>
     */
    private function baseQuery(int $companyId): Builder
    {
        return Activity::query()
            ->where('company_id', $companyId)
            ->where('causer_type', User::class)
            ->whereNotNull('causer_id');
    }

    /**
     * @return Collection<int, string>
     */
    private function subjectTypes(int $companyId): Collection
    {
        return $this->baseQuery($companyId)
            ->whereNotNull('subject_type')
            ->where('subject_type', '!=', '')
            ->distinct()
            ->orderBy('subject_type')
            ->pluck('subject_type')
            ->filter(fn (mixed $type): bool => is_string($type) && $type !== '')
            ->values();
    }
}
