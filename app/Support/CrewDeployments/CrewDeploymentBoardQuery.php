<?php

namespace App\Support\CrewDeployments;

use App\Models\EmployeeDeployment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

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
        ?string $status = null,
        ?string $search = null,
        ?int $rankId = null,
        ?int $clientId = null,
        ?int $companyVisaTypeId = null,
        ?string $sort = null,
        ?string $direction = null,
        int $perPage = 25,
    ): array {
        [$sortColumn, $sortDirection] = CrewDeploymentBoardSort::normalize($sort, $direction);

        if ($status === DeploymentStatus::AWAITING_JOIN) {
            $status = DeploymentStatus::ARRIVED;
        }

        $baseQuery = EmployeeDeployment::query()
            ->where('employee_deployments.company_id', $companyId)
            ->with([
                'employee.nationalityRef',
                'rank',
                'client',
                'companyVisaType',
                'vessel',
            ]);

        $this->applySearch($baseQuery, $search);
        $this->applyRelationFilters($baseQuery, $rankId, $clientId, $companyVisaTypeId);

        $summary = $this->buildSummary(clone $baseQuery);

        if ($status === DeploymentStatus::IN_HOME) {
            $inHomeIds = CrewDeploymentLatestRecords::inHomeDeploymentIds((clone $baseQuery)->get());

            if ($inHomeIds->isEmpty()) {
                $baseQuery->whereRaw('1 = 0');
            } else {
                $baseQuery->whereIn('employee_deployments.id', $inHomeIds);
            }
        } elseif ($status !== null && $status !== '') {
            $this->applyStatusFilter($baseQuery, $status);
        }

        CrewDeploymentBoardSort::apply($baseQuery, $sortColumn, $sortDirection);

        $paginator = $baseQuery
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
                ->orWhereHas('vessel', function (Builder $vesselQuery) use ($term) {
                    $vesselQuery->whereRaw('LOWER(name) LIKE ?', [$term]);
                })
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
                DeploymentStatus::JOIN_STANDBY => $builder
                    ->whereNotNull('join_standby_from')
                    ->whereDate('join_standby_from', '<=', $today)
                    ->where(function (Builder $dateQuery) use ($today) {
                        $dateQuery
                            ->whereNull('join_standby_to')
                            ->orWhereDate('join_standby_to', '>=', $today);
                    })
                    ->where(function (Builder $dateQuery) use ($today) {
                        $dateQuery
                            ->whereNull('joined_date')
                            ->orWhereDate('joined_date', '>', $today);
                    }),
                DeploymentStatus::LEAVE_STANDBY => $builder
                    ->whereNotNull('disembarked_date')
                    ->whereDate('disembarked_date', '<=', $today)
                    ->whereNotNull('leave_standby_from')
                    ->whereDate('leave_standby_from', '<=', $today)
                    ->where(function (Builder $dateQuery) use ($today) {
                        $dateQuery
                            ->whereNull('leave_standby_to')
                            ->orWhereDate('leave_standby_to', '>=', $today);
                    })
                    ->where(function (Builder $dateQuery) use ($today) {
                        $dateQuery
                            ->whereNull('travelled_date')
                            ->orWhereDate('travelled_date', '>', $today);
                    }),
                DeploymentStatus::ARRIVED => $builder
                    ->whereNotNull('arrived_date')
                    ->whereDate('arrived_date', '>=', $today)
                    ->whereNull('joined_date'),
                DeploymentStatus::TRAVEL => $builder
                    ->whereNotNull('disembarked_date')
                    ->whereDate('disembarked_date', '<=', $today)
                    ->whereNotNull('travelled_date'),
                DeploymentStatus::DISEMBARKED => $builder
                    ->whereNotNull('disembarked_date')
                    ->whereDate('disembarked_date', '=', $today)
                    ->whereNull('travelled_date')
                    ->where(function (Builder $notActiveLeaveStandbyQuery) use ($today) {
                        $notActiveLeaveStandbyQuery
                            ->whereNull('leave_standby_from')
                            ->orWhereDate('leave_standby_from', '>', $today)
                            ->orWhere(function (Builder $closedLeaveStandbyQuery) use ($today) {
                                $closedLeaveStandbyQuery
                                    ->whereNotNull('leave_standby_to')
                                    ->whereDate('leave_standby_to', '<', $today);
                            });
                    }),
                DeploymentStatus::UNKNOWN => $builder->where(function (Builder $unknownQuery) use ($today) {
                    $unknownQuery
                        ->where(function (Builder $missingDatesQuery) use ($today) {
                            $missingDatesQuery
                                ->whereNull('joined_date')
                                ->whereNull('arrived_date')
                                ->where(function (Builder $standbyQuery) use ($today) {
                                    $standbyQuery
                                        ->where(function (Builder $joinQuery) use ($today) {
                                            $joinQuery
                                                ->whereNull('join_standby_from')
                                                ->orWhereDate('join_standby_from', '>', $today)
                                                ->orWhere(function (Builder $closedJoinStandbyQuery) use ($today) {
                                                    $closedJoinStandbyQuery
                                                        ->whereNotNull('join_standby_to')
                                                        ->whereDate('join_standby_to', '<', $today);
                                                });
                                        })
                                        ->where(function (Builder $leaveQuery) use ($today) {
                                            $leaveQuery
                                                ->whereNull('leave_standby_from')
                                                ->orWhereDate('leave_standby_from', '>', $today)
                                                ->orWhere(function (Builder $closedLeaveStandbyQuery) use ($today) {
                                                    $closedLeaveStandbyQuery
                                                        ->whereNotNull('leave_standby_to')
                                                        ->whereDate('leave_standby_to', '<', $today);
                                                });
                                        });
                                });
                        })
                        ->orWhere(function (Builder $overdueArrivalQuery) use ($today) {
                            $overdueArrivalQuery
                                ->whereNotNull('arrived_date')
                                ->whereDate('arrived_date', '<', $today)
                                ->whereNull('joined_date')
                                ->where(function (Builder $notJoinStandbyQuery) use ($today) {
                                    $notJoinStandbyQuery
                                        ->whereNull('join_standby_from')
                                        ->orWhereDate('join_standby_from', '>', $today)
                                        ->orWhere(function (Builder $closedJoinStandbyQuery) use ($today) {
                                            $closedJoinStandbyQuery
                                                ->whereNotNull('join_standby_to')
                                                ->whereDate('join_standby_to', '<', $today);
                                        });
                                });
                        })
                        ->orWhere(function (Builder $overdueDisembarkQuery) use ($today) {
                            $overdueDisembarkQuery
                                ->whereNotNull('disembarked_date')
                                ->whereDate('disembarked_date', '<', $today)
                                ->whereNull('travelled_date')
                                ->where(function (Builder $notActiveLeaveStandbyQuery) use ($today) {
                                    $notActiveLeaveStandbyQuery
                                        ->whereNull('leave_standby_from')
                                        ->orWhereDate('leave_standby_from', '>', $today)
                                        ->orWhere(function (Builder $closedLeaveStandbyQuery) use ($today) {
                                            $closedLeaveStandbyQuery
                                                ->whereNotNull('leave_standby_to')
                                                ->whereDate('leave_standby_to', '<', $today);
                                        });
                                });
                        });
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
            DeploymentStatus::JOIN_STANDBY => 0,
            DeploymentStatus::LEAVE_STANDBY => 0,
            DeploymentStatus::ARRIVED => 0,
            DeploymentStatus::TRAVEL => 0,
            DeploymentStatus::IN_HOME => 0,
            DeploymentStatus::DISEMBARKED => 0,
            DeploymentStatus::UNKNOWN => 0,
        ];

        foreach ($deployments as $deployment) {
            $status = DeploymentStatus::resolve($deployment)['status'];
            $summary[$status] = ($summary[$status] ?? 0) + 1;
        }

        $summary[DeploymentStatus::IN_HOME] = CrewDeploymentLatestRecords::inHomeDeploymentIds($deployments)->count();
        $summary['total'] = $deployments->count();

        return $summary;
    }
}
