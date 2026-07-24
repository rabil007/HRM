<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\AnnouncementAttachment;
use App\Support\Announcements\Actions\AcknowledgeAnnouncement;
use App\Support\Announcements\BuildAnnouncementPublicLinks;
use App\Support\Announcements\ResolvePublicAnnouncementRecipient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicAnnouncementController extends Controller
{
    public function show(
        string $token,
        ResolvePublicAnnouncementRecipient $resolveRecipient,
        BuildAnnouncementPublicLinks $publicLinks,
    ): Response {
        $recipient = $resolveRecipient->handle($token);
        $announcement = $recipient->announcement;
        abort_unless($announcement !== null, 404);

        if ($recipient->read_at === null) {
            $recipient->update(['read_at' => now()]);
        }

        return Inertia::render('public/announcements/show', [
            'company_name' => (string) ($announcement->company?->name ?? config('app.name')),
            'announcement' => [
                'title' => $announcement->title,
                'body_html' => $announcement->body_html,
                'category' => $announcement->category->label(),
                'priority' => $announcement->priority->label(),
                'published_at' => $announcement->published_at?->toIso8601String(),
                'requires_acknowledgement' => $announcement->requires_acknowledgement,
                'attachments' => $announcement->attachments->map(fn (AnnouncementAttachment $attachment): array => [
                    'id' => $attachment->id,
                    'original_name' => $attachment->original_name,
                    'download_url' => $publicLinks->attachmentUrl($recipient, $attachment),
                ])->values()->all(),
            ],
            'acknowledged_at' => $recipient->acknowledged_at?->toIso8601String(),
            'acknowledge_url' => $announcement->requires_acknowledgement
                ? route('public.announcements.acknowledge', ['token' => $token])
                : null,
        ]);
    }

    public function acknowledge(
        string $token,
        ResolvePublicAnnouncementRecipient $resolveRecipient,
        AcknowledgeAnnouncement $acknowledge,
    ): RedirectResponse {
        $recipient = $resolveRecipient->handle($token);
        $acknowledge->handle($recipient);

        return redirect()
            ->route('public.announcements.show', ['token' => $token])
            ->with('success', 'Announcement acknowledged.');
    }

    public function downloadAttachment(
        string $token,
        AnnouncementAttachment $attachment,
        ResolvePublicAnnouncementRecipient $resolveRecipient,
    ): StreamedResponse {
        $recipient = $resolveRecipient->handle($token);
        abort_unless((int) $attachment->announcement_id === (int) $recipient->announcement_id, 404);
        abort_unless((int) $attachment->company_id === (int) $recipient->company_id, 404);

        return response()->streamDownload(function () use ($attachment): void {
            echo Storage::disk($attachment->disk)->get($attachment->path);
        }, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
        ]);
    }
}
