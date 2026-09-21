<?php

namespace App\Models;

use App\Enums\HistoricalCrewImportBatchStatus;
use App\Models\Concerns\LogsActivityWithCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Support\LogOptions;

class HistoricalCrewImportBatch extends Model
{
    use LogsActivityWithCompany;

    protected $guarded = [];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'company_id',
                'batch_no',
                'status',
                'original_filename',
                'total_rows',
                'imported_rows',
                'blocked_rows',
                'failed_rows',
            ])
            ->logOnlyDirty();
    }

    protected function casts(): array
    {
        return [
            'status' => HistoricalCrewImportBatchStatus::class,
            'total_rows' => 'integer',
            'ready_rows' => 'integer',
            'warning_rows' => 'integer',
            'blocked_rows' => 'integer',
            'imported_rows' => 'integer',
            'failed_rows' => 'integer',
            'skipped_rows' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'summary' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(HistoricalCrewImportRow::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CrewAssignment::class, 'historical_import_batch_id');
    }
}
