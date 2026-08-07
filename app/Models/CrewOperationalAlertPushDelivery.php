<?php

namespace App\Models;

use App\Enums\CrewOperationalAlertPushDeliveryStatus;
use Database\Factories\CrewOperationalAlertPushDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrewOperationalAlertPushDelivery extends Model
{
    /** @use HasFactory<CrewOperationalAlertPushDeliveryFactory> */
    use HasFactory;

    protected $fillable = [
        'company_id',
        'crew_operational_alert_id',
        'user_id',
        'notification_version',
        'status',
        'queued_at',
        'sent_at',
        'failed_at',
        'failure_category',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'crew_operational_alert_id' => 'integer',
            'user_id' => 'integer',
            'notification_version' => 'integer',
            'status' => CrewOperationalAlertPushDeliveryStatus::class,
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(CrewOperationalAlert::class, 'crew_operational_alert_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
