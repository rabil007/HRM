<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;

class RecruitmentRequirementHeadcountRevisionLine extends Model
{
    use LogsActivityWithCompany;

    protected $fillable = [
        'company_id',
        'headcount_revision_id',
        'recruitment_requirement_line_id',
        'position_id',
        'position_title',
        'old_headcount',
        'requested_headcount',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'old_headcount',
                'requested_headcount',
                'position_title',
            ])
            ->logOnlyDirty();
    }

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'headcount_revision_id' => 'integer',
            'recruitment_requirement_line_id' => 'integer',
            'position_id' => 'integer',
            'old_headcount' => 'integer',
            'requested_headcount' => 'integer',
        ];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequirementHeadcountRevision::class, 'headcount_revision_id');
    }

    public function requirementLine(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequirementLine::class, 'recruitment_requirement_line_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
