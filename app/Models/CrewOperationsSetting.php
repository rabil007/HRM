<?php

namespace App\Models;

use App\Enums\CrewOperationalAlertEmailDeliveryMode;
use App\Support\CrewOperations\CrewOperationsSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrewOperationsSetting extends Model
{
    use SoftDeletes;

    protected $table = 'crew_operations_settings';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saved(function (CrewOperationsSetting $setting): void {
            CrewOperationsSettings::clearCache((int) $setting->company_id);
        });

        static::deleted(function (CrewOperationsSetting $setting): void {
            CrewOperationsSettings::clearCache((int) $setting->company_id);
        });
    }

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'pool_department_ids' => 'array',
            'max_home_days' => 'integer',
            'sync_sea_service' => 'boolean',
            'sync_training_to_employee_training' => 'boolean',
            'notifications_enabled' => 'boolean',
            'notification_recipient_user_ids' => 'array',
            'alert_signoff_overdue' => 'boolean',
            'alert_signoff_no_relief' => 'boolean',
            'alert_relief_not_ready' => 'boolean',
            'alert_current_manning_gap' => 'boolean',
            'alert_projected_manning_gap' => 'boolean',
            'notification_email_delivery_mode' => CrewOperationalAlertEmailDeliveryMode::class,
            'notification_email_digest_at' => 'string',
            'notification_email_critical_immediate' => 'boolean',
            'notification_email_last_digest_date' => 'string',
            'notification_email_last_digest_dispatched_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
