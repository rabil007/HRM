<?php

namespace App\Models;

use App\Enums\DocumentGenerationTemplateFormat;
use App\Enums\DocumentGenerationTemplateStatus;
use App\Enums\DocumentGenerationTemplateVersionStatus;
use App\Models\Concerns\LogsActivityWithCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Support\LogOptions;

class DocumentGenerationTemplate extends Model
{
    use HasFactory;
    use LogsActivityWithCompany;

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'document_type_id',
        'template_format',
        'status',
        'published_version_id',
        'content',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DocumentGenerationTemplateStatus::class,
            'template_format' => DocumentGenerationTemplateFormat::class,
            'published_version_id' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'name',
                'description',
                'document_type_id',
                'template_format',
                'status',
                'published_version_id',
            ])
            ->logOnlyDirty();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentGenerationTemplateVersion::class, 'document_generation_template_id')
            ->orderBy('version');
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentGenerationTemplateVersion::class, 'published_version_id');
    }

    public function draftVersion(): HasOne
    {
        return $this->hasOne(DocumentGenerationTemplateVersion::class, 'document_generation_template_id')
            ->where('status', DocumentGenerationTemplateVersionStatus::Draft);
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', DocumentGenerationTemplateStatus::Active);
    }

    public function isContent(): bool
    {
        return $this->template_format === DocumentGenerationTemplateFormat::Content;
    }

    public function isPdfOverlay(): bool
    {
        return $this->template_format === DocumentGenerationTemplateFormat::PdfOverlay;
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     description: ?string,
     *     document_type_id: ?int,
     *     document_type_title: ?string,
     *     template_format: string,
     *     template_format_label: string,
     *     content: string,
     *     status: string,
     *     status_label: string,
     *     published_version_id: ?int,
     *     published_version: ?array<string, mixed>,
     *     draft_version: ?array<string, mixed>,
     *     created_by: ?int,
     *     created_by_name: ?string,
     *     updated_by: ?int,
     *     updated_by_name: ?string,
     *     created_at: ?string,
     *     updated_at: ?string
     * }
     */
    public function toBrowseArray(): array
    {
        $published = $this->publishedVersion;
        $draft = $this->draftVersion;

        // Content falls back to published version, then draft version, then legacy parent column
        $effectiveContent = (string) ($published?->content ?? $draft?->content ?? $this->content ?? '');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'document_type_id' => $this->document_type_id,
            'document_type_title' => $this->documentType?->title,
            'template_format' => $this->template_format->value,
            'template_format_label' => $this->template_format->label(),
            'content' => $effectiveContent,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'published_version_id' => $this->published_version_id,
            'published_version' => $published?->toArraySummary(),
            'draft_version' => $draft?->toArraySummary(),
            'created_by' => $this->created_by,
            'created_by_name' => $this->creator?->name,
            'updated_by' => $this->updated_by,
            'updated_by_name' => $this->updater?->name,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
