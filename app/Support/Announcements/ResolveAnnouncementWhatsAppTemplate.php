<?php

namespace App\Support\Announcements;

use App\Models\WhatsAppTemplate;

final class ResolveAnnouncementWhatsAppTemplate
{
    public const SLUG = 'announcement';

    public function handle(): ?WhatsAppTemplate
    {
        return WhatsAppTemplate::query()
            ->where('slug', self::SLUG)
            ->where('enabled', true)
            ->first();
    }
}
