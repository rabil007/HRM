<?php

namespace App\Support\SeaServices;

use App\Models\EmployeeSeaService;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeDirectoryQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class SeaServiceDirectoryQuery
{
    public function __construct(
        private readonly int $companyId,
        private readonly SeaServiceDirectoryFilters $filters,
    ) {}

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(int $perPage = 25): LengthAwarePaginator
    {
        $query = $this->baseQuery();

        $this->applyFilters($query);

        return $query
            ->orderByDesc('employee_sea_services.start_date')
            ->orderByDesc('employee_sea_services.id')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (EmployeeSeaService $seaService) => SeaServiceListResource::toArray($seaService));
    }

    /**
     * @return Builder<EmployeeSeaService>
     */
    public function exportQuery(): Builder
    {
        $query = $this->baseQuery()->with(['employee.branch:id,name']);

        $this->applyFilters($query);

        return $query
            ->orderByDesc('employee_sea_services.start_date')
            ->orderByDesc('employee_sea_services.id');
    }

    /**
     * @return Builder<EmployeeSeaService>
     */
    public function summaryQuery(): Builder
    {
        $query = EmployeeSeaService::query()
            ->where('employee_sea_services.company_id', $this->companyId);

        $this->applyFilters($query);

        return $query;
    }

    /**
     * @return Builder<EmployeeSeaService>
     */
    private function baseQuery(): Builder
    {
        $totalSeaServicesSubquery = fn (QueryBuilder $sub): QueryBuilder => $sub
            ->selectRaw('count(*)')
            ->from('employee_sea_services as ess_count')
            ->whereColumn('ess_count.employee_id', 'employee_sea_services.employee_id')
            ->where('ess_count.company_id', $this->companyId)
            ->whereNull('ess_count.deleted_at');

        return EmployeeSeaService::query()
            ->where('employee_sea_services.company_id', $this->companyId)
            ->addSelect('employee_sea_services.*')
            ->selectSub($totalSeaServicesSubquery, 'total_sea_services')
            ->with([
                'vesselType:id,name',
                'vessel:id,name',
                'rank:id,name',
                'client:id,name',
                'employee:id,name,employee_no,image,company_id,branch_id,department_id,position_id',
                'employee.department:id,name',
                'employee.position:id,title',
            ]);
    }

    /**
     * @param  Builder<EmployeeSeaService>  $query
     */
    private function applyFilters(Builder $query): void
    {
        $query
            ->when($this->filters->vesselId !== '', fn (Builder $inner) => $inner->where(
                'employee_sea_services.vessel_id',
                $this->filters->vesselId,
            ))
            ->when($this->filters->vesselTypeId !== '', fn (Builder $inner) => $inner->where(
                'employee_sea_services.vessel_type_id',
                $this->filters->vesselTypeId,
            ))
            ->when($this->filters->rankId !== '', fn (Builder $inner) => $inner->where(
                'employee_sea_services.rank_id',
                $this->filters->rankId,
            ))
            ->when($this->filters->clientId !== '', fn (Builder $inner) => $inner->where(
                'employee_sea_services.client_id',
                $this->filters->clientId,
            ))
            ->when($this->filters->offshore !== '', function (Builder $inner): void {
                if ($this->filters->offshore === 'offshore') {
                    $inner->where('employee_sea_services.is_offshore', true);
                } elseif ($this->filters->offshore === 'shore') {
                    $inner->where('employee_sea_services.is_offshore', false);
                }
            })
            ->when($this->filters->active === '1', fn (Builder $inner) => $inner->whereNull(
                'employee_sea_services.end_date',
            ))
            ->when($this->filters->startDate !== '', fn (Builder $inner) => $inner->whereDate(
                'employee_sea_services.start_date',
                '>=',
                $this->filters->startDate,
            ))
            ->when($this->filters->endDate !== '', fn (Builder $inner) => $inner->whereDate(
                'employee_sea_services.end_date',
                '<=',
                $this->filters->endDate,
            ))
            ->when($this->filters->search !== '', function (Builder $inner): void {
                $search = $this->filters->search;
                $like = '%'.$search.'%';

                $inner->where(function (Builder $searchQuery) use ($like): void {
                    $searchQuery
                        ->whereHas('vessel', function (Builder $vesselQuery) use ($like): void {
                            $vesselQuery->where('name', 'like', $like);
                        })
                        ->orWhereHas('vesselType', function (Builder $vesselTypeQuery) use ($like): void {
                            $vesselTypeQuery->where('name', 'like', $like);
                        })
                        ->orWhereHas('rank', function (Builder $rankQuery) use ($like): void {
                            $rankQuery->where('name', 'like', $like);
                        })
                        ->orWhereHas('client', function (Builder $clientQuery) use ($like): void {
                            $clientQuery->where('name', 'like', $like);
                        })
                        ->orWhereHas('employee', function (Builder $employeeQuery) use ($like): void {
                            $employeeQuery
                                ->where('name', 'like', $like)
                                ->orWhere('employee_no', 'like', $like);
                        });
                });
            })
            ->whereHas('employee', function (Builder $employeeQuery): void {
                $directoryFilters = new EmployeeDirectoryFilters(
                    branchId: $this->filters->branchId,
                    departmentId: $this->filters->departmentId,
                );

                EmployeeDirectoryQuery::applyAttributeFilters(
                    $employeeQuery,
                    $this->companyId,
                    $directoryFilters,
                    exceptDepartment: false,
                    exceptPosition: true,
                );
            });
    }
}
