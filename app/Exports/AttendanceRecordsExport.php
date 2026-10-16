<?php

namespace App\Exports;

use App\Models\AttendanceRecord;
use App\Support\Settings\CompanyTimezone;
use App\Support\Settings\PlatformDateFormatter;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class AttendanceRecordsExport implements FromCollection, WithHeadings, WithMapping
{
    /**
     * @var array<int, string>
     */
    private array $timezoneByCompanyId = [];

    /**
     * @param  Collection<int, AttendanceRecord>  $records
     */
    public function __construct(private Collection $records) {}

    public function collection(): Collection
    {
        $records = $this->records instanceof EloquentCollection
            ? $this->records
            : new EloquentCollection($this->records->all());

        $records->loadMissing([
            'employee:id,employee_no,name',
            'company:id,timezone',
        ]);

        return $this->records = $records;
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
        $timezone = $this->timezoneFor($record);

        return [
            $record->employee?->employee_no,
            $record->employee?->name,
            // Calendar date only — do not shift via timezone conversion.
            PlatformDateFormatter::make()->date($record->date?->toDateString(), null),
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

    private function timezoneFor(AttendanceRecord $record): string
    {
        $companyId = (int) $record->company_id;

        if ($companyId < 1) {
            return CompanyTimezone::forCompany(null);
        }

        if (! array_key_exists($companyId, $this->timezoneByCompanyId)) {
            $this->timezoneByCompanyId[$companyId] = CompanyTimezone::forCompany(
                $record->relationLoaded('company') ? $record->company : $companyId,
            );
        }

        return $this->timezoneByCompanyId[$companyId];
    }

    private function formatDateTime(?\DateTimeInterface $value, string $timezone): ?string
    {
        if ($value === null) {
            return null;
        }

        // Preserve the absolute instant from Eloquent casts — do not re-parse
        // the wall-clock string (that reinterprets it in the process timezone).
        $carbon = $value instanceof Carbon
            ? $value->copy()
            : Carbon::instance($value);

        return PlatformDateFormatter::make()->dateTime(
            $carbon->timezone($timezone),
            null,
            twelveHour: true,
        );
    }
}
