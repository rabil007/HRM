<?php

namespace App\Http\Controllers\Organization\Announcements;

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementChannel;
use App\Enums\AnnouncementPriority;
use App\Enums\AnnouncementStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Announcements\PreviewAnnouncementChannelsRequest;
use App\Models\Announcement;
use App\Models\Company;
use App\Support\Announcements\BuildAnnouncementChannelPreview;
use App\Support\Announcements\SanitizeAnnouncementHtml;
use Illuminate\Http\JsonResponse;

class PreviewAnnouncementChannelsController extends Controller
{
    public function __invoke(
        PreviewAnnouncementChannelsRequest $request,
        BuildAnnouncementChannelPreview $preview,
    ): JsonResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $validated = $request->validated();
        $channels = array_values(array_unique(array_map('strval', $validated['channels'])));
        $usesWhatsApp = in_array(AnnouncementChannel::WhatsApp->value, $channels, true);
        $whatsAppMessage = isset($validated['whatsapp_message'])
            ? trim((string) $validated['whatsapp_message'])
            : '';

        $announcement = new Announcement([
            'company_id' => $companyId,
            'title' => $validated['title'],
            'body_html' => SanitizeAnnouncementHtml::handle($validated['body_html']),
            'category' => AnnouncementCategory::from($validated['category']),
            'priority' => AnnouncementPriority::from($validated['priority']),
            'status' => AnnouncementStatus::Draft,
            'channels' => $channels,
            'whatsapp_link' => $usesWhatsApp ? ($validated['whatsapp_link'] ?? null) : null,
            'whatsapp_message' => $usesWhatsApp && $whatsAppMessage !== '' ? $whatsAppMessage : null,
            'whatsapp_template_id' => $usesWhatsApp
                ? ($validated['whatsapp_template_id'] ?? null)
                : null,
        ]);

        $company = Company::query()->whereKey($companyId)->first(['id', 'name']);
        $announcement->setRelation('company', $company);
        $announcement->setRelation('attachments', collect());

        $templateId = $usesWhatsApp && isset($validated['whatsapp_template_id'])
            ? (int) $validated['whatsapp_template_id']
            : null;

        return response()->json([
            'ok' => true,
            'channel_previews' => $preview->handle($announcement, $templateId),
        ]);
    }
}
