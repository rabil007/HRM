<?php

namespace App\Models;

use App\Enums\CrewOperationalAlertEmailDeliveryStatus;
use Database\Factories\CrewOperationalAlertEmailDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrewOperationalAlertEmailDelivery extends Model
{
    /** @use HasFactory<CrewOperationalAlertEmailDeliveryFactory> */
    use HasFactory;

    protected $fillable = [
        'company_id',
        'crew_operational_alert_id',
        'user_id',
        'notification_version',
        'status',
        'queued_at',
        'dispatched_at',
        'dispatch_claimed_at',
        'sent_at',
        'failed_at',
        'failure_category',
        'attempt_count',
        'last_attempt_at',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'crew_operational_alert_id' => 'integer',
            'user_id' => 'integer',
            'notification_version' => 'integer',
            'status' => CrewOperationalAlertEmailDeliveryStatus::class,
            'queued_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'dispatch_claimed_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'attempt_count' => 'integer',
            'last_attempt_at' => 'datetime',
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
