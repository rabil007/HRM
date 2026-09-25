<?php

namespace App\Support\Reports;

use App\Enums\CrewAccommodationStatus;
use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
use App\Models\Hotel;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class HotelCheckInCheckoutQuery
{
    private const SORTS = [
        'check_in',
        'check_out',
        'employee',
        'hotel',
        'vessel',
        'stay_days',
    ];

    public function __construct(
        private readonly int $companyId,
        private readonly HotelCheckInCheckoutFilters $filters,
        private readonly string $timezone,
        private readonly ?User $user = null,
    ) {}

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(int $perPage = 25): LengthAwarePaginator
    {
        return $this->ordered($this->filteredQuery())
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (CrewAccommodationStay $stay): array => HotelCheckInCheckoutPresenter::toArray($stay, $this->timezone));
    }

    /**
     * @return Builder<CrewAccommodationStay>
     */
    public function exportQuery(): Builder
    {
        return $this->ordered($this->filteredQuery());
    }

    /**
     * @return array{total: int, currently_checked_in: int, check_in_today: int, checking_out_today: int, upcoming: int, checked_out: int}
     */
    public function summary(): array
    {
        $query = $this->filteredQuery(withRelations: false, ignoreStayStatus: true);
        $today = Carbon::now($this->timezone)->toDateString();

        $counts = (clone $query)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN DATE(crew_accommodation_stays.check_in_date) <= '{$today}' AND (crew_accommodation_stays.check_out_date IS NULL OR DATE(crew_accommodation_stays.check_out_date) > '{$today}') THEN 1 ELSE 0 END) as currently_checked_in")
            ->selectRaw("SUM(CASE WHEN DATE(crew_accommodation_stays.check_in_date) = '{$today}' AND (crew_accommodation_stays.check_out_date IS NULL OR DATE(crew_accommodation_stays.check_out_date) >= '{$today}') THEN 1 ELSE 0 END) as check_in_today")
            ->selectRaw("SUM(CASE WHEN DATE(crew_accommodation_stays.check_out_date) = '{$today}' THEN 1 ELSE 0 END) as checking_out_today")
            ->selectRaw("SUM(CASE WHEN DATE(crew_accommodation_stays.check_in_date) > '{$today}' THEN 1 ELSE 0 END) as upcoming")
            ->selectRaw("SUM(CASE WHEN DATE(crew_accommodation_stays.check_out_date) < '{$today}' THEN 1 ELSE 0 END) as checked_out")
            ->first();

        return [
            'total' => (int) ($counts?->total ?? 0),
            'currently_checked_in' => (int) ($counts?->currently_checked_in ?? 0),
            'check_in_today' => (int) ($counts?->check_in_today ?? 0),
            'checking_out_today' => (int) ($counts?->checking_out_today ?? 0),
            'upcoming' => (int) ($counts?->upcoming ?? 0),
            'checked_out' => (int) ($counts?->checked_out ?? 0),
        ];
    }

    /**
     * @return Builder<CrewAccommodationStay>
     */
    private function filteredQuery(bool $withRelations = true, bool $ignoreStayStatus = false): Builder
    {
        $query = CrewAccommodationStay::query()
            ->where('crew_accommodation_stays.company_id', $this->companyId);

        if ($this->user !== null) {
            EmployeeVisibilityScope::whereHas($query, $this->user, $this->companyId, 'assignment.employee');
        }

        $accommodationStatus = $this->filters->accommodationStatus !== ''
            ? $this->filters->accommodationStatus
            : CrewAccommodationStatus::Hotel->value;

        $query->where('crew_accommodation_stays.accommodation_status', $accommodationStatus);

        if ($this->filters->search !== '') {
            $search = '%'.strtolower($this->filters->search).'%';
            $query->where(function (Builder $q) use ($search): void {
                $q->whereHas('assignment.employee', function (Builder $emp) use ($search): void {
                    $emp->whereRaw('LOWER(employees.name) LIKE ?', [$search])
                        ->orWhereRaw('LOWER(employees.employee_no) LIKE ?', [$search]);
                })
                    ->orWhereHas('hotel', function (Builder $h) use ($search): void {
                        $h->whereRaw('LOWER(hotels.name) LIKE ?', [$search]);
                    })
                    ->orWhereHas('roomType', function (Builder $rt) use ($search): void {
                        $rt->whereRaw('LOWER(room_types.name) LIKE ?', [$search]);
                    })
                    ->orWhereHas('assignment', function (Builder $a) use ($search): void {
                        $a->whereRaw('LOWER(crew_assignments.assignment_no) LIKE ?', [$search]);
                    })
                    ->orWhereHas('assignment.vessel', function (Builder $v) use ($search): void {
                        $v->whereRaw('LOWER(vessels.name) LIKE ?', [$search]);
                    });
            });
        }

        if ($this->filters->hotelId !== '') {
            $query->where('crew_accommodation_stays.hotel_id', (int) $this->filters->hotelId);
        }

        if ($this->filters->roomTypeId !== '') {
            $query->where('crew_accommodation_stays.room_type_id', (int) $this->filters->roomTypeId);
        }

        if ($this->filters->stayType !== '') {
            $query->where('crew_accommodation_stays.stay_type', $this->filters->stayType);
        }

        if (! $ignoreStayStatus && $this->filters->stayStatus !== '') {
            $today = Carbon::now($this->timezone)->toDateString();
            match ($this->filters->stayStatus) {
                'currently_checked_in' => $query
                    ->whereDate('crew_accommodation_stays.check_in_date', '<=', $today)
                    ->where(function (Builder $q) use ($today): void {
                        $q->whereNull('crew_accommodation_stays.check_out_date')
                            ->orWhereDate('crew_accommodation_stays.check_out_date', '>', $today);
                    }),
                'check_in_today' => $query
                    ->whereDate('crew_accommodation_stays.check_in_date', '=', $today)
                    ->where(function (Builder $q) use ($today): void {
                        $q->whereNull('crew_accommodation_stays.check_out_date')
                            ->orWhereDate('crew_accommodation_stays.check_out_date', '>=', $today);
                    }),
                'checking_out_today' => $query
                    ->whereDate('crew_accommodation_stays.check_out_date', '=', $today),
                'upcoming' => $query
                    ->whereDate('crew_accommodation_stays.check_in_date', '>', $today),
                'checked_out' => $query
                    ->whereDate('crew_accommodation_stays.check_out_date', '<', $today),
                default => null,
            };
        }

        if ($this->filters->checkInFrom !== '') {
            $query->whereDate('crew_accommodation_stays.check_in_date', '>=', $this->filters->checkInFrom);
        }

        if ($this->filters->checkInTo !== '') {
            $query->whereDate('crew_accommodation_stays.check_in_date', '<=', $this->filters->checkInTo);
        }

        if ($this->filters->checkOutFrom !== '') {
            $query->whereDate('crew_accommodation_stays.check_out_date', '>=', $this->filters->checkOutFrom);
        }

        if ($this->filters->checkOutTo !== '') {
            $query->whereDate('crew_accommodation_stays.check_out_date', '<=', $this->filters->checkOutTo);
        }

        if ($this->filters->vesselId !== '') {
            $query->whereHas('assignment', fn (Builder $q) => $q->where('vessel_id', (int) $this->filters->vesselId));
        }

        if ($this->filters->rankId !== '') {
            $query->whereHas('assignment', fn (Builder $q) => $q->where('rank_id', (int) $this->filters->rankId));
        }

        if ($this->filters->clientId !== '') {
            $query->whereHas('assignment', fn (Builder $q) => $q->where('client_id', (int) $this->filters->clientId));
        }

        if ($withRelations) {
            $query->with([
                'assignment' => fn ($q) => $q->select([
                    'id',
                    'company_id',
                    'assignment_no',
                    'employee_id',
                    'rank_id',
                    'vessel_id',
                    'client_id',
                    'status',
                    'current_phase_id',
                ]),
                'assignment.employee:id,company_id,employee_no,name',
                'assignment.rank:id,name',
                'assignment.vessel:id,name',
                'assignment.client:id,name',
                'assignment.currentPhase:id,phase_code,status',
                'hotel:id,company_id,name',
                'roomType:id,company_id,hotel_id,name',
                'startedFromPhase:id,phase_code,status',
            ]);
        }

        return $query;
    }

    /**
     * @param  Builder<CrewAccommodationStay>  $query
     * @return Builder<CrewAccommodationStay>
     */
    private function ordered(Builder $query): Builder
    {
        $sort = in_array($this->filters->sort, self::SORTS, true) ? $this->filters->sort : 'check_in';
        $direction = $this->filters->direction;

        match ($sort) {
            'check_out' => $query->orderBy('crew_accommodation_stays.check_out_date', $direction),
            'hotel' => $query->orderBy(
                Hotel::query()->select('name')->whereColumn('hotels.id', 'crew_accommodation_stays.hotel_id'),
                $direction,
            ),
            'employee' => $query->orderBy(
                CrewAssignment::query()
                    ->join('employees', 'employees.id', '=', 'crew_assignments.employee_id')
                    ->select('employees.name')
                    ->whereColumn('crew_assignments.id', 'crew_accommodation_stays.crew_assignment_id'),
                $direction,
            ),
            'vessel' => $query->orderBy(
                CrewAssignment::query()
                    ->join('vessels', 'vessels.id', '=', 'crew_assignments.vessel_id')
                    ->select('vessels.name')
                    ->whereColumn('crew_assignments.id', 'crew_accommodation_stays.crew_assignment_id'),
                $direction,
            ),
            'stay_days' => (function () use ($query, $direction): void {
                $today = Carbon::now($this->timezone)->toDateString();
                $driver = $query->getConnection()->getDriverName();
                $expression = $driver === 'sqlite'
                    ? "julianday(COALESCE(crew_accommodation_stays.check_out_date, '{$today}')) - julianday(crew_accommodation_stays.check_in_date)"
                    : "DATEDIFF(COALESCE(crew_accommodation_stays.check_out_date, '{$today}'), crew_accommodation_stays.check_in_date)";
                $query->orderByRaw("({$expression}) {$direction}");
            })(),
            default => $query->orderBy('crew_accommodation_stays.check_in_date', $direction),
        };

        return $query->orderByDesc('crew_accommodation_stays.id');
    }
}
