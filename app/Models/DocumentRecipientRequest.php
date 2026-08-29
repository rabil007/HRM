<?php

namespace App\Models;

use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentRecipientType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentRecipientRequest extends Model
{
    use HasFactory;

    public const EXPIRY_DAYS = 14;

    protected $fillable = [
        'company_id',
        'document_instance_id',
        'source_document_instance_version_id',
        'result_document_instance_version_id',
        'document_workflow_request_id',
        'document_signing_flow_id',
        'signing_step_sequence',
        'signature_slot_key',
        'signing_step_label_snapshot',
        'action',
        'recipient_type',
        'recipient_role',
        'employee_id',
        'recipient_user_id',
        'recipient_name_snapshot',
        'status',
        'token_hash',
        'expires_at',
        'reminder_policy_snapshot',
        'requested_by',
        'requested_at',
        'first_viewed_at',
        'completed_at',
        'cancelled_at',
        'cancelled_by',
        'signed_name',
        'signature_image_path',
        'consent_at',
        'submitted_ip',
        'user_agent',
        'source_checksum_sha256',
        'result_checksum_sha256',
        'acknowledgement_text_snapshot',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => DocumentRecipientAction::class,
            'recipient_type' => DocumentRecipientType::class,
            'recipient_role' => DocumentRecipientRole::class,
            'status' => DocumentRecipientRequestStatus::class,
            'expires_at' => 'datetime',
            'reminder_policy_snapshot' => 'array',
            'requested_at' => 'datetime',
            'first_viewed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'consent_at' => 'datetime',
        ];
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

    public function resultVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentInstanceVersion::class, 'result_document_instance_version_id');
    }

    public function workflowRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentWorkflowRequest::class, 'document_workflow_request_id');
    }

    public function signingFlow(): BelongsTo
    {
        return $this->belongsTo(DocumentSigningFlow::class, 'document_signing_flow_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DocumentRecipientRequestEvent::class)
            ->orderBy('occurred_at');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(DocumentRecipientRequestDelivery::class)
            ->orderByDesc('delivery_sequence');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isAwaitingAction(): bool
    {
        return $this->status === DocumentRecipientRequestStatus::AwaitingAction && ! $this->isExpired();
    }

    public function isPublicTokenRecipient(): bool
    {
        return $this->recipient_type === DocumentRecipientType::SubjectEmployee;
    }

    public function isInternalSigner(): bool
    {
        return $this->recipient_type === DocumentRecipientType::CompanyUser
            && $this->recipient_role !== null
            && $this->recipient_role->isInternalSigner();
    }

    public function isInternalCompanySignatory(): bool
    {
        return $this->recipient_type === DocumentRecipientType::CompanyUser
            && $this->recipient_role === DocumentRecipientRole::CompanySignatory;
    }

    public function isInternalManager(): bool
    {
        return $this->recipient_type === DocumentRecipientType::CompanyUser
            && $this->recipient_role === DocumentRecipientRole::Manager;
    }

    public function isLinkedToSigningFlow(): bool
    {
        return $this->document_signing_flow_id !== null;
    }
}
