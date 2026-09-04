<?php

namespace App\Models;

use App\Enums\DocumentTemplateLayoutValidationRunStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentTemplateLayoutValidationRun extends Model
{
    use HasFactory;
    use MassPrunable;

    public const RETENTION_DAYS = 30;

    protected $fillable = [
        'company_id',
        'document_generation_template_id',
        'document_generation_template_version_id',
        'requested_by',
        'mode',
        'employee_id',
        'authoritative',
        'fingerprint',
        'status',
        'issues',
        'effective_font_sizes',
        'placement_config',
        'validated_with',
        'reference',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'authoritative' => 'boolean',
            'status' => DocumentTemplateLayoutValidationRunStatus::class,
            'issues' => 'array',
            'effective_font_sizes' => 'array',
            'placement_config' => 'array',
            'validated_with' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<DocumentGenerationTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentGenerationTemplate::class, 'document_generation_template_id');
    }

    /**
     * @return BelongsTo<DocumentGenerationTemplateVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentGenerationTemplateVersion::class, 'document_generation_template_version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);

        $keepIds = static::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy([
                'company_id',
                'document_generation_template_id',
                'document_generation_template_version_id',
                'fingerprint',
                'mode',
                'authoritative',
                'employee_id',
            ])
            ->pluck('id');

        return static::query()
            ->whereIn('status', [
                DocumentTemplateLayoutValidationRunStatus::Valid,
                DocumentTemplateLayoutValidationRunStatus::Invalid,
                DocumentTemplateLayoutValidationRunStatus::Unavailable,
                DocumentTemplateLayoutValidationRunStatus::Stale,
            ])
            ->where(function (Builder $query) use ($cutoff): void {
                $query->where('finished_at', '<=', $cutoff)
                    ->orWhere(function (Builder $query) use ($cutoff): void {
                        $query->whereNull('finished_at')
                            ->where('created_at', '<=', $cutoff);
                    });
            })
            ->whereNotIn('id', $keepIds);
    }
}
