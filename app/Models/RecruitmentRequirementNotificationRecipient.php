<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecruitmentRequirementNotificationRecipient extends Model
{
    protected $fillable = [
        'company_id',
        'recruitment_requirement_id',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'recruitment_requirement_id' => 'integer',
            'user_id' => 'integer',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
