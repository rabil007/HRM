<?php

namespace App\Models;

use App\Enums\DocumentRecipientRequestDeliveryChannel;
use App\Enums\DocumentRecipientRequestDeliveryPurpose;
use App\Enums\DocumentRecipientRequestDeliveryStatus;
use Database\Factories\DocumentRecipientRequestDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRecipientRequestDelivery extends Model
{
    /** @use HasFactory<DocumentRecipientRequestDeliveryFactory> */
    use HasFactory;

    protected $fillable = [
        'company_id',
        'document_recipient_request_id',
        'channel',
        'purpose',
        'automation_key',
        'scheduled_for',
        'delivery_sequence',
        'destination_snapshot',
        'template_slug',
        'subject_snapshot',
        'access_token_hash',
        'status',
        'attempt_count',
        'last_attempt_at',
        'claimed_at',
        'dispatched_at',
        'sent_at',
        'failed_at',
        'revoked_at',
        'failure_category',
        'requested_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => DocumentRecipientRequestDeliveryChannel::class,
            'purpose' => DocumentRecipientRequestDeliveryPurpose::class,
            'status' => DocumentRecipientRequestDeliveryStatus::class,
            'scheduled_for' => 'datetime',
            'last_attempt_at' => 'datetime',
            'claimed_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function recipientRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentRecipientRequest::class, 'document_recipient_request_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isActiveAccessToken(): bool
    {
        return $this->access_token_hash !== null
            && ! $this->isRevoked()
            && $this->status !== DocumentRecipientRequestDeliveryStatus::Suppressed;
    }
}
