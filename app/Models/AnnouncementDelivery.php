<?php

namespace App\Models;

use App\Enums\AnnouncementChannel;
use App\Enums\AnnouncementDeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnouncementDelivery extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'channel' => AnnouncementChannel::class,
            'status' => AnnouncementDeliveryStatus::class,
            'attempt_count' => 'integer',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(AnnouncementRecipient::class, 'announcement_recipient_id');
    }
}
