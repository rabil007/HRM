<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;

class RecruitmentCandidateInternalReminder extends Model
{
    use HasFactory;
    use LogsActivityWithCompany;

    protected $fillable = [
        'company_id',
        'recruitment_candidate_id',
        'schedule_type',
        'schedule_key',
        'milestone',
        'target_date',
        'delivery_key',
        'user_id',
        'status',
        'claimed_at',
        'sent_at',
        'read_at',
        'skip_reason',
        'title',
        'summary',
        'url',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'recruitment_candidate_id' => 'integer',
            'user_id' => 'integer',
            'target_date' => 'date',
            'claimed_at' => 'datetime',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'schedule_type',
                'schedule_key',
                'milestone',
                'target_date',
                'status',
                'sent_at',
                'skip_reason',
            ])
            ->logOnlyDirty();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(RecruitmentCandidate::class, 'recruitment_candidate_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }
}
