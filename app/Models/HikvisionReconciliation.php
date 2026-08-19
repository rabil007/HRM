<?php

namespace App\Models;

use App\Support\Hikvision\HikvisionFetchOrigin;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HikvisionReconciliation extends Model
{
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'hikvision_reconciliations';

    protected $fillable = [
        'company_id',
        'target_date',
        'status',
        'fetch_origin',
        'events_fetched_count',
        'device_events_count',
        'mobile_events_count',
        'attendance_synced_count',
        'reconciled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'target_date' => 'date:Y-m-d',
            'events_fetched_count' => 'integer',
            'device_events_count' => 'integer',
            'mobile_events_count' => 'integer',
            'attendance_synced_count' => 'integer',
            'reconciled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public static function wasSuccessfullyProcessed(int $companyId, string $targetDate): bool
    {
        return self::query()
            ->forCompany($companyId)
            ->whereDate('target_date', $targetDate)
            ->where('status', self::STATUS_COMPLETED)
            ->exists();
    }

    public static function isReconciled(int $companyId, string $targetDate): bool
    {
        return self::wasSuccessfullyProcessed($companyId, $targetDate);
    }

    public static function shouldDispatchReconciliation(
        int $companyId,
        string $targetDate,
        CarbonInterface $cycleCutoff,
    ): bool {
        $reconciliation = self::query()
            ->forCompany($companyId)
            ->whereDate('target_date', $targetDate)
            ->first();

        if ($reconciliation === null || $reconciliation->status !== self::STATUS_COMPLETED) {
            return true;
        }

        if ($reconciliation->reconciled_at === null) {
            return true;
        }

        return $reconciliation->reconciled_at->lt($cycleCutoff);
    }

    public static function markCompleted(
        int $companyId,
        string $targetDate,
        string|HikvisionFetchOrigin $origin,
        int $fetchedCount = 0,
        int $deviceCount = 0,
        int $mobileCount = 0,
        int $attendanceSyncedCount = 0,
    ): self {
        $originValue = $origin instanceof HikvisionFetchOrigin ? $origin->value : (string) $origin;

        return self::query()->updateOrCreate(
            [
                'company_id' => $companyId,
                'target_date' => $targetDate,
            ],
            [
                'status' => self::STATUS_COMPLETED,
                'fetch_origin' => $originValue,
                'events_fetched_count' => $fetchedCount,
                'device_events_count' => $deviceCount,
                'mobile_events_count' => $mobileCount,
                'attendance_synced_count' => $attendanceSyncedCount,
                'reconciled_at' => now(),
            ],
        );
    }

    public static function recordAttendanceSynced(int $companyId, string $targetDate, int $syncedCount): void
    {
        self::query()
            ->forCompany($companyId)
            ->whereDate('target_date', $targetDate)
            ->update([
                'attendance_synced_count' => $syncedCount,
            ]);
    }

    public static function markFailed(int $companyId, string $targetDate, ?string $reason = null): self
    {
        return self::query()->updateOrCreate(
            [
                'company_id' => $companyId,
                'target_date' => $targetDate,
            ],
            [
                'status' => self::STATUS_FAILED,
            ],
        );
    }
}
