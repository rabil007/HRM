<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentGenerationRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'document_generation_template_id',
        'document_generation_template_version_id',
        'filters',
        'status',
        'total_targeted',
        'generated_count',
        'skipped_count',
        'failed_count',
        'correlation_id',
        'triggered_by',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'total_targeted' => 'integer',
            'generated_count' => 'integer',
            'skipped_count' => 'integer',
            'failed_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentGenerationTemplate::class, 'document_generation_template_id');
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentGenerationTemplateVersion::class, 'document_generation_template_version_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DocumentGenerationRunItem::class, 'document_generation_run_id');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(DocumentInstance::class, 'document_generation_run_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }
}
