<?php

namespace App\Models;

use App\Enums\DocumentRecipientRequestEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRecipientRequestEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'document_recipient_request_id',
        'event',
        'actor_user_id',
        'occurred_at',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => DocumentRecipientRequestEventType::class,
            'occurred_at' => 'datetime',
            'metadata' => 'array',
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

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
