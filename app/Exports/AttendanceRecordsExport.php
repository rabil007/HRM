<?php

namespace App\Exports;

use App\Models\AttendanceRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class AttendanceRecordsExport implements FromCollection, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, AttendanceRecord>  $records
     */
    public function __construct(private Collection $records) {}

    public function collection(): Collection
    {
        return $this->records;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Employee No',
            'Employee',
            'Date',
            'Clock In',
            'Clock Out',
            'Hours',
            'Overtime Hours',
            'Late Minutes',
            'Status',
            'Source',
            'Notes',
        ];
    }

    /**
     * @param  AttendanceRecord  $record
     * @return list<mixed>
     */
    public function map($record): array
    {
        $record->loadMissing('employee:id,employee_no,name');
        $timezone = (string) config('app.timezone', 'UTC');

        return [
            $record->employee?->employee_no,
            $record->employee?->name,
            $record->date?->timezone($timezone)->format('d-m-Y'),
            $this->formatDateTime($record->clock_in, $timezone),
            $this->formatDateTime($record->clock_out, $timezone),
            $record->hours_worked,
            $record->overtime_hours,
            $record->late_minutes,
            ucfirst(str_replace('_', ' ', (string) $record->status)),
            $record->source !== null ? ucfirst((string) $record->source) : null,
            $record->notes,
        ];
    }

    private function formatDateTime(?\DateTimeInterface $value, string $timezone): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)
            ->timezone($timezone)
            ->format('d-m-Y g:i A');
    }
}
