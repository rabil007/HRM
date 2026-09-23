<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;

class RecruitmentRequirementAttachment extends Model
{
    use LogsActivityWithCompany;

    protected $fillable = [
        'company_id',
        'recruitment_requirement_id',
        'file_path',
        'original_file_name',
        'mime_type',
        'file_size_bytes',
        'file_checksum',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'recruitment_requirement_id' => 'integer',
            'file_size_bytes' => 'integer',
            'uploaded_by' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'original_file_name',
                'mime_type',
                'file_size_bytes',
            ])
            ->logOnlyDirty();
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequirement::class, 'recruitment_requirement_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
