<?php

namespace App\Models;

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementPriority;
use App\Enums\AnnouncementStatus;
use App\Models\Concerns\LogsActivityWithCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;

class Announcement extends Model
{
    use LogsActivityWithCompany;
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'category' => AnnouncementCategory::class,
            'priority' => AnnouncementPriority::class,
            'status' => AnnouncementStatus::class,
            'channels' => 'array',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'requires_acknowledgement' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'company_id',
                'title',
                'category',
                'priority',
                'status',
                'channels',
                'scheduled_at',
                'published_at',
                'expires_at',
            ])
            ->logOnlyDirty();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function audiences(): HasMany
    {
        return $this->hasMany(AnnouncementAudience::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AnnouncementAttachment::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(AnnouncementRecipient::class);
    }
}
