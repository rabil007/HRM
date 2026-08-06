<?php

namespace App\Http\Controllers\Settings\MasterData\Concerns;

use App\Support\Pagination\ResolvesPerPage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

trait PaginatesMasterDataIndex
{
    use ResolvesPerPage;

    /**
     * @param  list<string>  $searchColumns
     * @param  (callable(mixed): mixed)|null  $through
     * @return array{
     *     items: list<mixed>,
     *     pagination: array{
     *         current_page: int,
     *         last_page: int,
     *         per_page: int,
     *         total: int,
     *         from: int|null,
     *         to: int|null
     *     },
     *     search: string
     * }
     */
    protected function paginateMasterDataIndex(
        Request $request,
        Builder $query,
        array $searchColumns = ['name'],
        ?callable $through = null,
    ): array {
        $perPage = $this->resolvePerPage($request);
        $search = trim((string) $request->query('search', ''));

        if ($search !== '' && $searchColumns !== []) {
            $query->where(function (Builder $inner) use ($search, $searchColumns): void {
                foreach ($searchColumns as $index => $column) {
                    if (str_contains($column, '.')) {
                        [$relation, $relationColumn] = explode('.', $column, 2);

                        if ($index === 0) {
                            $inner->whereHas(
                                $relation,
                                fn (Builder $relationQuery) => $relationQuery->where($relationColumn, 'like', "%{$search}%"),
                            );
                        } else {
                            $inner->orWhereHas(
                                $relation,
                                fn (Builder $relationQuery) => $relationQuery->where($relationColumn, 'like', "%{$search}%"),
                            );
                        }

                        continue;
                    }

                    if ($index === 0) {
                        $inner->where($column, 'like', "%{$search}%");
                    } else {
                        $inner->orWhere($column, 'like', "%{$search}%");
                    }
                }
            });
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query
            ->paginate($perPage)
            ->withQueryString();

        if ($through !== null) {
            $paginator->getCollection()->transform($through);
        }

        return [
            'items' => $paginator->items(),
            'pagination' => $this->paginationMeta($paginator),
            'search' => $search,
        ];
    }
}
