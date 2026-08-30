<?php

namespace App\Models;

use App\Enums\DocumentLifecycleAutomationStage;
use App\Enums\DocumentLifecycleAutomationStatus;
use Database\Factories\DocumentLifecycleAutomationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentLifecycleAutomation extends Model
{
    /** @use HasFactory<DocumentLifecycleAutomationFactory> */
    use HasFactory;

    protected $fillable = [
        'company_id',
        'document_instance_id',
        'source_document_instance_version_id',
        'document_generation_template_version_id',
        'document_workflow_preset_id',
        'document_signing_preset_id',
        'document_workflow_request_id',
        'document_signing_flow_id',
        'policy_snapshot',
        'status',
        'stage',
        'blocked_code',
        'blocked_message',
        'initiated_by',
        'started_at',
        'blocked_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'policy_snapshot' => 'array',
            'status' => DocumentLifecycleAutomationStatus::class,
            'stage' => DocumentLifecycleAutomationStage::class,
            'started_at' => 'datetime',
            'blocked_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($query->getModel()->getTable().'.company_id', $companyId);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function documentInstance(): BelongsTo
    {
        return $this->belongsTo(DocumentInstance::class);
    }

    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentInstanceVersion::class, 'source_document_instance_version_id');
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentGenerationTemplateVersion::class, 'document_generation_template_version_id');
    }

    public function workflowPreset(): BelongsTo
    {
        return $this->belongsTo(DocumentWorkflowPreset::class, 'document_workflow_preset_id');
    }

    public function signingPreset(): BelongsTo
    {
        return $this->belongsTo(DocumentSigningPreset::class, 'document_signing_preset_id');
    }

    public function workflowRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentWorkflowRequest::class, 'document_workflow_request_id');
    }

    public function signingFlow(): BelongsTo
    {
        return $this->belongsTo(DocumentSigningFlow::class, 'document_signing_flow_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function hasWorkflow(): bool
    {
        $snapshot = $this->policy_snapshot;

        return is_array($snapshot) && ($snapshot['workflow_preset_id'] ?? null) !== null;
    }

    public function hasSigning(): bool
    {
        $snapshot = $this->policy_snapshot;

        return is_array($snapshot) && ($snapshot['signing_preset_id'] ?? null) !== null;
    }

    public function snapshottedWorkflowPresetId(): ?int
    {
        $id = $this->policy_snapshot['workflow_preset_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    public function snapshottedSigningPresetId(): ?int
    {
        $id = $this->policy_snapshot['signing_preset_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }
}
