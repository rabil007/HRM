<?php

namespace App\Models;

use Database\Factories\AttendanceRecordFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    /** @use HasFactory<AttendanceRecordFactory> */
    use HasFactory;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_BIOMETRIC = 'biometric';

    public const SOURCE_MOBILE = 'mobile';

    public const STATUS_PRESENT = 'present';

    public const STATUS_ABSENT = 'absent';

    public const STATUS_LATE = 'late';

    public const STATUS_HALF_DAY = 'half_day';

    public const STATUS_HOLIDAY = 'holiday';

    public const STATUS_WEEKEND = 'weekend';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'clock_in' => 'datetime',
            'clock_out' => 'datetime',
            'clock_in_lat' => 'decimal:8',
            'clock_in_lng' => 'decimal:8',
            'clock_out_lat' => 'decimal:8',
            'clock_out_lng' => 'decimal:8',
            'hours_worked' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
            'late_minutes' => 'integer',
        ];
    }

    /**
     * @return list<string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_PRESENT,
            self::STATUS_ABSENT,
            self::STATUS_LATE,
            self::STATUS_HALF_DAY,
            self::STATUS_HOLIDAY,
            self::STATUS_WEEKEND,
        ];
    }

    /**
     * @return list<string>
     */
    public static function sourceOptions(): array
    {
        return [
            self::SOURCE_MANUAL,
            self::SOURCE_BIOMETRIC,
            self::SOURCE_MOBILE,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @param  array{search?: string, date_from?: string, date_to?: string, employee_id?: string, status?: string, source?: string}  $filters
     */
    public function scopeFiltered(Builder $query, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->whereHas('employee', function (Builder $employeeQuery) use ($search): void {
                $employeeQuery->where('name', 'like', '%'.$search.'%')
                    ->orWhere('employee_no', 'like', '%'.$search.'%');
            });
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));

        if ($dateFrom !== '') {
            $query->whereDate('date', '>=', $dateFrom);
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));

        if ($dateTo !== '') {
            $query->whereDate('date', '<=', $dateTo);
        }

        $employeeId = trim((string) ($filters['employee_id'] ?? ''));

        if ($employeeId !== '') {
            $query->where('employee_id', $employeeId);
        }

        $status = trim((string) ($filters['status'] ?? ''));

        if ($status !== '' && in_array($status, self::statusOptions(), true)) {
            $query->where('status', $status);
        }

        $source = trim((string) ($filters['source'] ?? ''));

        if ($source !== '' && in_array($source, self::sourceOptions(), true)) {
            $query->where('source', $source);
        }

        return $query;
    }

    public static function calculateHoursWorked(?\DateTimeInterface $clockIn, ?\DateTimeInterface $clockOut): ?float
    {
        if ($clockIn === null || $clockOut === null) {
            return null;
        }

        $minutes = max(0, $clockIn->diffInMinutes($clockOut, false));

        return round($minutes / 60, 2);
    }
}
