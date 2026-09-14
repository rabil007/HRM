<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Support\LogOptions;

class CompanyDocumentExpiryNotificationSetting extends Model
{
    use LogsActivityWithCompany;

    protected $fillable = [
        'company_id',
        'enabled',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['enabled'])
            ->logOnlyDirty();
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CompanyDocumentExpiryNotificationRecipient::class, 'setting_id');
    }

    public function toRecipients(): HasMany
    {
        return $this->hasMany(CompanyDocumentExpiryNotificationRecipient::class, 'setting_id')
            ->where('type', 'to');
    }

    public function ccRecipients(): HasMany
    {
        return $this->hasMany(CompanyDocumentExpiryNotificationRecipient::class, 'setting_id')
            ->where('type', 'cc');
    }
}
