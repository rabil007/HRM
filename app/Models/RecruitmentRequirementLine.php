<?php

namespace App\Models;

use App\Enums\Recruitment\RequirementLineStatus;
use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\RecruitmentRequirementLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;

class RecruitmentRequirementLine extends Model
{
    /** @use HasFactory<RecruitmentRequirementLineFactory> */
    use HasFactory;

    use LogsActivityWithCompany;

    protected $fillable = [
        'company_id',
        'recruitment_requirement_id',
        'position_id',
        'required_headcount',
        'line_notes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'recruitment_requirement_id' => 'integer',
            'position_id' => 'integer',
            'required_headcount' => 'integer',
            'status' => RequirementLineStatus::class,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'position_id',
                'required_headcount',
                'status',
            ])
            ->logOnlyDirty();
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequirement::class, 'recruitment_requirement_id');
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
