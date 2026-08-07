<?php

namespace App\Models;

use Database\Factories\CrewOperationalAlertRecipientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrewOperationalAlertRecipient extends Model
{
    /** @use HasFactory<CrewOperationalAlertRecipientFactory> */
    use HasFactory;

    protected $fillable = [
        'company_id',
        'crew_operational_alert_id',
        'user_id',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'crew_operational_alert_id' => 'integer',
            'user_id' => 'integer',
            'read_at' => 'datetime',
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
