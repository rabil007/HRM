<?php

namespace App\Models;

use App\Enums\DocumentGenerationTemplateVersionStatus;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentGenerationTemplateVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'document_generation_template_id',
        'version',
        'status',
        'content',
        'source_pdf_path',
        'source_pdf_original_name',
        'source_pdf_size_bytes',
        'source_pdf_page_count',
        'placement_config',
        'signature_placement_config',
        'document_workflow_preset_id',
        'document_signing_preset_id',
        'published_at',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => DocumentGenerationTemplateVersionStatus::class,
            'source_pdf_size_bytes' => 'integer',
            'source_pdf_page_count' => 'integer',
            'placement_config' => 'array',
            'signature_placement_config' => 'array',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (DocumentGenerationTemplateVersion $version): void {
            $rawOriginal = $version->getRawOriginal('status') ?? $version->getOriginal('status');
            $originalStatus = $rawOriginal instanceof DocumentGenerationTemplateVersionStatus
                ? $rawOriginal
                : DocumentGenerationTemplateVersionStatus::tryFrom((string) $rawOriginal);

            // Lifecycle status transition validations based on original persisted state
            if ($originalStatus === DocumentGenerationTemplateVersionStatus::Published) {
                if ($version->isDirty('status')) {
                    if ($version->status === DocumentGenerationTemplateVersionStatus::Draft) {
                        throw new DomainException('Cannot transition a published version to draft.');
                    }
                    if ($version->status !== DocumentGenerationTemplateVersionStatus::Archived) {
                        throw new DomainException("Cannot transition a published version to {$version->status->value}.");
                    }
                }
            } elseif ($originalStatus === DocumentGenerationTemplateVersionStatus::Archived) {
                if ($version->isDirty('status')) {
                    if ($version->status === DocumentGenerationTemplateVersionStatus::Draft) {
                        throw new DomainException('Cannot transition an archived version to draft.');
                    }
                    if ($version->status === DocumentGenerationTemplateVersionStatus::Published) {
                        throw new DomainException('Cannot transition an archived version to published.');
                    }
                    throw new DomainException('Cannot modify status on an archived template version.');
                }
            }

            // Once originally Published or Archived, renderable and identity attributes must remain immutable
            if ($originalStatus !== DocumentGenerationTemplateVersionStatus::Draft) {
                $dirty = array_keys($version->getDirty());
                $protectedAttributes = [
                    'content',
                    'source_pdf_path',
                    'source_pdf_original_name',
                    'source_pdf_size_bytes',
                    'source_pdf_page_count',
                    'placement_config',
                    'signature_placement_config',
                    'document_workflow_preset_id',
                    'document_signing_preset_id',
                    'version',
                    'published_at',
                    'company_id',
                    'document_generation_template_id',
                ];

                foreach ($protectedAttributes as $attr) {
                    if (in_array($attr, $dirty, true)) {
                        throw new DomainException("Cannot modify {$attr} on an immutable template version.");
                    }
                }
            }
        });
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentGenerationTemplate::class, 'document_generation_template_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(DocumentInstance::class, 'document_generation_template_version_id');
    }

    public function workflowPreset(): BelongsTo
    {
        return $this->belongsTo(DocumentWorkflowPreset::class, 'document_workflow_preset_id');
    }

    public function signingPreset(): BelongsTo
    {
        return $this->belongsTo(DocumentSigningPreset::class, 'document_signing_preset_id');
    }

    public function isDraft(): bool
    {
        return $this->status === DocumentGenerationTemplateVersionStatus::Draft;
    }

    public function isPublished(): bool
    {
        return $this->status === DocumentGenerationTemplateVersionStatus::Published;
    }

    public function isArchived(): bool
    {
        return $this->status === DocumentGenerationTemplateVersionStatus::Archived;
    }

    public function isEditable(): bool
    {
        return $this->isDraft();
    }

    public function assertEditable(): void
    {
        if (! $this->isEditable()) {
            throw new DomainException('Published or archived template versions cannot be edited.');
        }
    }

    /**
     * @return array{
     *     id: int,
     *     version: int,
     *     status: string,
     *     status_label: string,
     *     content: ?string,
     *     source_pdf_original_name: ?string,
     *     source_pdf_size_bytes: ?int,
     *     source_pdf_page_count: ?int,
     *     placement_count: int,
     *     has_placements: bool,
     *     placement_config: ?array,
     *     has_signature_placement: bool,
     *     signature_placement_config: ?array,
     *     document_workflow_preset_id: int|null,
     *     document_signing_preset_id: int|null,
     *     published_at: ?string,
     *     created_at: ?string,
     *     updated_at: ?string
     * }
     */
    public function toArraySummary(): array
    {
        $placements = is_array($this->placement_config['placements'] ?? null)
            ? $this->placement_config['placements']
            : (is_array($this->placement_config) && ! isset($this->placement_config['schema_version']) ? $this->placement_config : []);

        $signaturePlacements = is_array($this->signature_placement_config['placements'] ?? null)
            ? $this->signature_placement_config['placements']
            : [];

        return [
            'id' => $this->id,
            'version' => $this->version,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'content' => $this->content,
            'source_pdf_original_name' => $this->source_pdf_original_name,
            'source_pdf_size_bytes' => $this->source_pdf_size_bytes,
            'source_pdf_page_count' => $this->source_pdf_page_count,
            'placement_count' => count($placements),
            'has_placements' => count($placements) > 0,
            'placement_config' => $this->placement_config,
            'has_signature_placement' => count($signaturePlacements) > 0,
            'signature_placement_config' => $this->signature_placement_config,
            'document_workflow_preset_id' => $this->document_workflow_preset_id !== null
                ? (int) $this->document_workflow_preset_id
                : null,
            'document_signing_preset_id' => $this->document_signing_preset_id !== null
                ? (int) $this->document_signing_preset_id
                : null,
            'published_at' => $this->published_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
