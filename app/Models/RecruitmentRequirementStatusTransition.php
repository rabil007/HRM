<?php

namespace App\Models;

use App\Enums\Recruitment\RequirementStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecruitmentRequirementStatusTransition extends Model
{
    protected $fillable = [
        'company_id',
        'recruitment_requirement_id',
        'from_status',
        'to_status',
        'performed_by',
        'reason',
        'context',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'recruitment_requirement_id' => 'integer',
            'performed_by' => 'integer',
            'context' => 'array',
        ];
    }

    public function getFromStatusEnumAttribute(): ?RequirementStatus
    {
        return $this->from_status !== null
            ? RequirementStatus::tryFrom((string) $this->from_status)
            : null;
    }

    public function getToStatusEnumAttribute(): ?RequirementStatus
    {
        return RequirementStatus::tryFrom((string) $this->to_status);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequirement::class, 'recruitment_requirement_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
