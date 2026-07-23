<?php

namespace App\Models;

use App\Enums\AnnouncementAudienceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnouncementAudience extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'audience_type' => AnnouncementAudienceType::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }
}
