<?php

namespace App\Models;

use App\Enums\DocumentExpiryNotificationDeliveryType;
use App\Enums\DocumentExpiryNotificationRecipientKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentExpiryNotificationRuleRecipient extends Model
{
    protected $fillable = [
        'rule_id',
        'company_id',
        'recipient_kind',
        'user_id',
        'email',
        'delivery_type',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipient_kind' => DocumentExpiryNotificationRecipientKind::class,
            'delivery_type' => DocumentExpiryNotificationDeliveryType::class,
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(DocumentExpiryNotificationRule::class, 'rule_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
