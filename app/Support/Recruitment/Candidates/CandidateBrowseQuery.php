<?php

namespace App\Support\Recruitment\Candidates;

use App\Enums\Recruitment\CandidateStage;
use App\Models\RecruitmentCandidate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator as ConcreteLengthAwarePaginator;

final class CandidateBrowseQuery
{
    /**
     * @return array{
     *     mode: string,
     *     paginator: LengthAwarePaginator|null,
     *     kanban: array<string, array{total: int, paginator: LengthAwarePaginator, from: int|null, to: int|null}>|null,
     *     stage_totals: array<string, int>,
     *     filters: array<string, mixed>,
     *     search: string,
     * }
     */
    public static function get(Request $request, int $companyId): array
    {
        $mode = $request->string('view')->toString() === 'kanban' ? 'kanban' : 'table';
        $search = trim($request->string('search')->toString());
        $perPage = self::resolvePerPage($request->integer('per_page', 15));
        $requirementId = self::nullableInt($request->input('requirement_id'));
        $positionId = self::nullableInt($request->input('position_id'));
        $stage = self::nullableString($request->input('stage'));
        $outcome = self::nullableString($request->input('outcome'));
        $lineId = self::nullableInt($request->input('requirement_line_id'));

        $base = self::baseQuery($companyId, $search, $requirementId, $positionId, $lineId, $outcome);
        $stageFilter = $stage !== null && CandidateStage::tryFrom($stage) !== null ? $stage : null;

        $stageTotals = [];
        foreach (CandidateStage::kanbanColumns() as $column) {
            $stageTotals[$column->value] = (clone $base)->where('stage', $column->value)->count();
        }

        if ($mode === 'kanban') {
            $kanban = [];
            $columns = CandidateStage::kanbanColumns();

            if ($stageFilter !== null) {
                $columns = array_values(array_filter(
                    $columns,
                    fn (CandidateStage $column): bool => $column->value === $stageFilter,
                ));
            }

            foreach ($columns as $column) {
                $pageKey = 'page_'.$column->value;
                $throughPage = max(0, (int) $request->input('through_page_'.$column->value, 0));
                $page = max(1, (int) $request->input($pageKey, 1));
                $columnQuery = (clone $base)->where('stage', $column->value)
                    ->orderByDesc('updated_at')
                    ->orderByDesc('id');
                $total = $stageTotals[$column->value];

                if ($throughPage > 1) {
                    $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
                    $effectiveThrough = min($throughPage, $lastPage);
                    $take = $perPage * $effectiveThrough;
                    $items = (clone $columnQuery)->limit($take)->get();
                    $itemCount = $items->count();

                    $kanban[$column->value] = [
                        'total' => $total,
                        'paginator' => new ConcreteLengthAwarePaginator(
                            $items,
                            $total,
                            $perPage,
                            $effectiveThrough,
                            [
                                'path' => $request->url(),
                                'pageName' => $pageKey,
                                'query' => $request->query(),
                            ],
                        ),
                        'from' => $itemCount > 0 ? 1 : null,
                        'to' => $itemCount > 0 ? $itemCount : null,
                    ];
                } else {
                    $paginator = $columnQuery
                        ->paginate($perPage, ['*'], $pageKey, $page)
                        ->withQueryString();

                    $kanban[$column->value] = [
                        'total' => $total,
                        'paginator' => $paginator,
                        'from' => $paginator->firstItem(),
                        'to' => $paginator->lastItem(),
                    ];
                }
            }

            return [
                'mode' => 'kanban',
                'paginator' => null,
                'kanban' => $kanban,
                'stage_totals' => $stageTotals,
                'filters' => [
                    'view' => 'kanban',
                    'search' => $search,
                    'per_page' => $perPage,
                    'requirement_id' => $requirementId,
                    'requirement_line_id' => $lineId,
                    'position_id' => $positionId,
                    'stage' => $stageFilter,
                    'outcome' => $outcome,
                ],
                'search' => $search,
            ];
        }

        $tableQuery = clone $base;
        if ($stageFilter !== null) {
            $tableQuery->where('stage', $stageFilter);
        }

        $paginator = $tableQuery
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return [
            'mode' => 'table',
            'paginator' => $paginator,
            'kanban' => null,
            'stage_totals' => $stageTotals,
            'filters' => [
                'view' => 'table',
                'search' => $search,
                'per_page' => $perPage,
                'requirement_id' => $requirementId,
                'requirement_line_id' => $lineId,
                'position_id' => $positionId,
                'stage' => $stageFilter,
                'outcome' => $outcome,
            ],
            'search' => $search,
        ];
    }

    private static function baseQuery(
        int $companyId,
        string $search,
        ?int $requirementId,
        ?int $positionId,
        ?int $lineId,
        ?string $outcome,
    ): Builder {
        $query = RecruitmentCandidate::query()
            ->forCompany($companyId)
            ->with([
                'nationality:id,name',
                'requirement:id,company_id,assigned_to,status,requirement_number',
                'line:id,recruitment_requirement_id,position_id,status,salary_min,salary_max,salary_currency_code',
                'line.position:id,title',
                'currentOffer',
            ]);

        if ($requirementId !== null) {
            $query->where('recruitment_requirement_id', $requirementId);
        }

        if ($lineId !== null) {
            $query->where('recruitment_requirement_line_id', $lineId);
        }

        if ($positionId !== null) {
            $query->whereHas('line', fn (Builder $q) => $q->where('position_id', $positionId));
        }

        if ($outcome !== null) {
            if ($outcome === 'pending') {
                $query->whereNull('interview_outcome');
            } else {
                $query->where('interview_outcome', $outcome);
            }
        }

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $q) use ($like): void {
                $q->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('requirement_number_snapshot', 'like', $like)
                    ->orWhere('position_title_snapshot', 'like', $like);
            });
        }

        return $query;
    }

    private static function resolvePerPage(int $perPage): int
    {
        return in_array($perPage, [10, 15, 25, 50], true) ? $perPage : 15;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
