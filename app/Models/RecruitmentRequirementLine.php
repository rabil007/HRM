<?php

namespace App\Models;

use App\Enums\Recruitment\RequirementLineStatus;
use App\Models\Concerns\LogsActivityWithCompany;
use App\Support\Recruitment\Candidates\CandidateDeletionGuard;
use Database\Factories\RecruitmentRequirementLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'salary_min',
        'salary_max',
        'salary_currency_code',
    ];

    protected static function booted(): void
    {
        static::deleting(function (RecruitmentRequirementLine $line): void {
            CandidateDeletionGuard::assertLineMayBeDeleted($line);
        });
    }

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'recruitment_requirement_id' => 'integer',
            'position_id' => 'integer',
            'required_headcount' => 'integer',
            'status' => RequirementLineStatus::class,
            'salary_min' => 'decimal:2',
            'salary_max' => 'decimal:2',
            'salary_currency_code' => 'string',
        ];
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(RecruitmentCandidate::class, 'recruitment_requirement_line_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'position_id',
                'required_headcount',
                'status',
                'salary_min',
                'salary_max',
                'salary_currency_code',
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
