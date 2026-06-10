<?php

namespace App\Support\CrewDeployments;

use App\Models\EmployeeDeployment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class CrewDeploymentBoardQuery
{
    /**
     * @return array{
     *     summary: array<string, int>,
     *     paginator: LengthAwarePaginator<int, EmployeeDeployment>
     * }
     */
    public function paginate(
        int $companyId,
        string $view = 'current',
        ?string $status = null,
        ?string $search = null,
        ?int $rankId = null,
        ?int $clientId = null,
        ?int $companyVisaTypeId = null,
        int $perPage = 25,
    ): array {
        $baseQuery = EmployeeDeployment::query()
            ->where('employee_deployments.company_id', $companyId)
            ->with([
                'employee.nationalityRef',
                'rank',
                'client',
                'companyVisaType',
            ]);

        if ($view === 'current') {
            $currentIds = $this->currentDeploymentIds($companyId);

            if ($currentIds === []) {
                $baseQuery->whereRaw('1 = 0');
            } else {
                $baseQuery->whereIn('employee_deployments.id', $currentIds);
            }
        }

        $this->applySearch($baseQuery, $search);
        $this->applyRelationFilters($baseQuery, $rankId, $clientId, $companyVisaTypeId);

        $summary = $this->buildSummary(clone $baseQuery);

        if ($status !== null && $status !== '') {
            $this->applyStatusFilter($baseQuery, $status);
        }

        $paginator = $baseQuery
            ->orderByDesc('employee_deployments.joined_date')
            ->orderByDesc('employee_deployments.created_at')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (EmployeeDeployment $deployment) => EmployeeDeploymentPresenter::toArray($deployment));

        return [
            'summary' => $summary,
            'paginator' => $paginator,
        ];
    }

    /**
     * @param  Builder<EmployeeDeployment>  $query
     */
    private function applySearch(Builder $query, ?string $search): void
    {
        if ($search === null || trim($search) === '') {
            return;
        }

        $term = '%'.mb_strtolower(trim($search)).'%';

        $query->where(function (Builder $builder) use ($term) {
            $builder
                ->whereHas('employee', function (Builder $employeeQuery) use ($term) {
                    $employeeQuery
                        ->whereRaw('LOWER(employee_no) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(name) LIKE ?', [$term]);
                })
                ->orWhereRaw('LOWER(vessel_name) LIKE ?', [$term])
                ->orWhereRaw('LOWER(remarks) LIKE ?', [$term]);
        });
    }

    /**
     * @param  Builder<EmployeeDeployment>  $query
     */
    private function applyRelationFilters(
        Builder $query,
        ?int $rankId,
        ?int $clientId,
        ?int $companyVisaTypeId,
    ): void {
        if ($rankId !== null) {
            $query->where('employee_deployments.rank_id', $rankId);
        }

        if ($clientId !== null) {
            $query->where('employee_deployments.client_id', $clientId);
        }

        if ($companyVisaTypeId !== null) {
            $query->where('employee_deployments.company_visa_type_id', $companyVisaTypeId);
        }
    }

    /**
     * @param  Builder<EmployeeDeployment>  $query
     */
    private function applyStatusFilter(Builder $query, string $status): void
    {
        $today = CarbonImmutable::today();

        $query->where(function (Builder $builder) use ($status, $today) {
            match ($status) {
                DeploymentStatus::ON_VESSEL => $builder
                    ->whereNotNull('joined_date')
                    ->whereDate('joined_date', '<=', $today)
                    ->where(function (Builder $dateQuery) use ($today) {
                        $dateQuery
                            ->whereNull('disembarked_date')
                            ->orWhereDate('disembarked_date', '>', $today);
                    }),
                DeploymentStatus::STANDBY => $builder
                    ->whereNotNull('standby_from')
                    ->whereNotNull('standby_to')
                    ->whereDate('standby_from', '<=', $today)
                    ->whereDate('standby_to', '>=', $today)
                    ->where(function (Builder $dateQuery) use ($today) {
                        $dateQuery
                            ->whereNull('joined_date')
                            ->orWhereDate('joined_date', '>', $today)
                            ->orWhere(function (Builder $onBoardQuery) use ($today) {
                                $onBoardQuery
                                    ->whereNotNull('disembarked_date')
                                    ->whereDate('disembarked_date', '<=', $today);
                            });
                    }),
                DeploymentStatus::AWAITING_JOIN => $builder
                    ->whereNotNull('arrived_date')
                    ->whereNull('joined_date'),
                DeploymentStatus::TRAVEL => $builder
                    ->whereNotNull('disembarked_date')
                    ->whereDate('disembarked_date', '<=', $today)
                    ->whereNotNull('travelled_date'),
                DeploymentStatus::DISEMBARKED => $builder
                    ->whereNotNull('disembarked_date')
                    ->whereDate('disembarked_date', '<=', $today)
                    ->whereNull('travelled_date'),
                DeploymentStatus::UNKNOWN => $builder
                    ->whereNull('joined_date')
                    ->whereNull('arrived_date')
                    ->where(function (Builder $standbyQuery) use ($today) {
                        $standbyQuery
                            ->whereNull('standby_from')
                            ->orWhereNull('standby_to')
                            ->orWhereDate('standby_from', '>', $today)
                            ->orWhereDate('standby_to', '<', $today);
                    }),
                default => null,
            };
        });
    }

    /**
     * @param  Builder<EmployeeDeployment>  $query
     * @return array<string, int>
     */
    private function buildSummary(Builder $query): array
    {
        $deployments = (clone $query)->get();

        $summary = [
            DeploymentStatus::ON_VESSEL => 0,
            DeploymentStatus::STANDBY => 0,
            DeploymentStatus::AWAITING_JOIN => 0,
            DeploymentStatus::TRAVEL => 0,
            DeploymentStatus::DISEMBARKED => 0,
            DeploymentStatus::UNKNOWN => 0,
        ];

        foreach ($deployments as $deployment) {
            $status = DeploymentStatus::resolve($deployment)['status'];
            $summary[$status] = ($summary[$status] ?? 0) + 1;
        }

        $summary['total'] = $deployments->count();

        return $summary;
    }

    /**
     * @return list<int>
     */
    private function currentDeploymentIds(int $companyId): array
    {
        $today = CarbonImmutable::today();

        return EmployeeDeployment::query()
            ->where('company_id', $companyId)
            ->orderByDesc('joined_date')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('employee_id')
            ->map(function (Collection $group) use ($today): int {
                $openTour = $group->first(function (EmployeeDeployment $deployment) use ($today): bool {
                    if ($deployment->joined_date === null || $deployment->joined_date->gt($today)) {
                        return false;
                    }

                    return $deployment->disembarked_date === null || $deployment->disembarked_date->gt($today);
                });

                return ($openTour ?? $group->first())->id;
            })
            ->values()
            ->all();
    }
}
