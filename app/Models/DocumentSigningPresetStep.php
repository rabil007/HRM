<?php

namespace App\Models;

use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentSigningTargetType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSigningPresetStep extends Model
{
    protected $fillable = [
        'company_id',
        'document_signing_preset_id',
        'sequence',
        'recipient_role',
        'target_type',
        'target_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipient_role' => DocumentRecipientRole::class,
            'target_type' => DocumentSigningTargetType::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function preset(): BelongsTo
    {
        return $this->belongsTo(DocumentSigningPreset::class, 'document_signing_preset_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
