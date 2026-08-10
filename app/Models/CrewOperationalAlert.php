<?php

namespace App\Models;

use App\Enums\CrewOperationalAlertSeverity;
use App\Enums\CrewOperationalAlertStatus;
use App\Enums\CrewOperationalAlertType;
use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\CrewOperationalAlertFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Support\LogOptions;

class CrewOperationalAlert extends Model
{
    /** @use HasFactory<CrewOperationalAlertFactory> */
    use HasFactory;

    use LogsActivityWithCompany;

    protected $fillable = [
        'company_id',
        'type',
        'severity',
        'status',
        'dedupe_key',
        'title',
        'message',
        'context',
        'detected_at',
        'last_detected_at',
        'resolved_at',
        'notification_version',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'type' => CrewOperationalAlertType::class,
            'severity' => CrewOperationalAlertSeverity::class,
            'status' => CrewOperationalAlertStatus::class,
            'context' => 'array',
            'detected_at' => 'datetime',
            'last_detected_at' => 'datetime',
            'resolved_at' => 'datetime',
            'notification_version' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'company_id',
                'type',
                'severity',
                'status',
                'dedupe_key',
                'title',
                'resolved_at',
                'notification_version',
            ])
            ->logOnlyDirty();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CrewOperationalAlertRecipient::class);
    }

    public function pushDeliveries(): HasMany
    {
        return $this->hasMany(CrewOperationalAlertPushDelivery::class);
    }
}
