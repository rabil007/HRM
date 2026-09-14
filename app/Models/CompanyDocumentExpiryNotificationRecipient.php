<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyDocumentExpiryNotificationRecipient extends Model
{
    protected $fillable = [
        'setting_id',
        'user_id',
        'type',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(CompanyDocumentExpiryNotificationSetting::class, 'setting_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
