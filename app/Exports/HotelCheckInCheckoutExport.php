<?php

namespace App\Exports;

use App\Models\CrewAccommodationStay;
use App\Support\Reports\HotelCheckInCheckoutPresenter;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

final class HotelCheckInCheckoutExport implements FromQuery, WithHeadings, WithMapping, WithStrictNullComparison
{
    /**
     * @param  Builder<CrewAccommodationStay>  $query
     */
    public function __construct(
        private readonly Builder $query,
        private readonly string $timezone,
    ) {}

    /**
     * @param  Builder<CrewAccommodationStay>  $query
     */
    public static function forQuery(Builder $query, string $timezone): self
    {
        return new self($query, $timezone);
    }

    public function query(): Builder
    {
        return $this->query;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Stay Record ID',
            'Employee No.',
            'Employee Name',
            'Rank',
            'Hotel',
            'Room Type',
            'Stay Type',
            'Accommodation Status',
            'Check-In',
            'Check-Out',
            'Stay Status',
            'Stay Days',
            'Assignment No.',
            'Assignment Status',
            'Vessel',
            'Client',
            'Starting Checkpoint',
            'Current Crew Phase',
            'Assignment Record ID',
        ];
    }

    /**
     * @param  CrewAccommodationStay  $row
     * @return list<mixed>
     */
    public function map($row): array
    {
        $item = HotelCheckInCheckoutPresenter::toArray($row, $this->timezone);

        return [
            $item['id'],
            $item['employee']['employee_no'],
            $item['employee']['name'],
            $item['assignment']['rank_name'],
            $item['hotel']['name'],
            $item['room_type']['name'],
            $item['stay_type_label'],
            $item['accommodation_status_label'],
            $item['check_in_date'],
            $item['check_out_date'] ?? 'Open',
            $item['stay_status_label'],
            $item['stay_days'],
            $item['assignment']['assignment_no'],
            $item['assignment']['status_label'],
            $item['assignment']['vessel_name'],
            $item['assignment']['client_name'],
            $item['starting_checkpoint'] ?? '—',
            $item['current_phase'] ?? '—',
            $item['assignment']['id'],
        ];
    }
}
