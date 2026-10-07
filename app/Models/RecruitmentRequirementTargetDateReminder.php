<?php

namespace App\Models;

use App\Enums\Recruitment\RequirementTargetDateReminderMilestone;
use App\Enums\Recruitment\RequirementTargetDateReminderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecruitmentRequirementTargetDateReminder extends Model
{
    protected $fillable = [
        'company_id',
        'recruitment_requirement_id',
        'target_date',
        'milestone',
        'delivery_key',
        'status',
        'claim_token',
        'claimed_at',
        'sent_at',
        'skip_reason',
        'primary_recipient_user_id',
        'cc_user_ids',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'recruitment_requirement_id' => 'integer',
            'target_date' => 'date',
            'milestone' => RequirementTargetDateReminderMilestone::class,
            'status' => RequirementTargetDateReminderStatus::class,
            'claimed_at' => 'datetime',
            'sent_at' => 'datetime',
            'primary_recipient_user_id' => 'integer',
            'cc_user_ids' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequirement::class, 'recruitment_requirement_id');
    }

    public function primaryRecipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'primary_recipient_user_id');
    }
}
