<?php

namespace App\Http\Controllers\Public;

use App\Enums\AnnouncementChannel;
use App\Enums\AnnouncementDeliveryStatus;
use App\Enums\AnnouncementStatus;
use App\Http\Controllers\Controller;
use App\Models\AnnouncementAttachment;
use App\Models\AnnouncementRecipient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicAnnouncementController extends Controller
{
    public function show(string $token): Response
    {
        $recipient = $this->recipient($token);
        $announcement = $recipient->announcement;
        abort_unless($announcement !== null, 404);
        abort_unless(in_array($announcement->status, [
            AnnouncementStatus::Published,
            AnnouncementStatus::PartiallyDelivered,
            AnnouncementStatus::Expired,
        ], true), 404);

        $this->markRead($recipient);

        return Inertia::render('public/announcements/show', [
            'announcement' => [
                'title' => $announcement->title,
                'body_html' => $announcement->body_html,
                'priority' => $announcement->priority->label(),
                'category' => $announcement->category->label(),
                'published_at' => $announcement->published_at?->toIso8601String(),
                'expires_at' => $announcement->expires_at?->toIso8601String(),
                'requires_acknowledgement' => (bool) $announcement->requires_acknowledgement,
                'acknowledged_at' => $recipient->acknowledged_at?->toIso8601String(),
                'attachments' => $announcement->attachments->map(fn (AnnouncementAttachment $attachment): array => [
                    'id' => $attachment->id,
                    'original_name' => $attachment->original_name,
                    'download_url' => route('public.announcements.attachments.download', [
                        'token' => $token,
                        'attachment' => $attachment->id,
                    ]),
                ])->values()->all(),
            ],
            'token' => $token,
            'can_acknowledge' => $announcement->requires_acknowledgement && $recipient->acknowledged_at === null,
        ]);
    }

    public function acknowledge(Request $request, string $token): RedirectResponse
    {
        $recipient = $this->recipient($token);
        $announcement = $recipient->announcement;
        abort_unless($announcement !== null && $announcement->requires_acknowledgement, 404);

        if ($recipient->acknowledged_at === null) {
            $recipient->update(['acknowledged_at' => now()]);

            activity()
                ->useLog('announcements')
                ->event('acknowledged')
                ->performedOn($announcement)
                ->withProperties(['employee_id' => $recipient->employee_id])
                ->tap(fn ($activity) => $activity->company_id = $recipient->company_id)
                ->log('Announcement acknowledged');
        }

        return redirect()
            ->route('public.announcements.show', ['token' => $token])
            ->with('success', 'Announcement acknowledged.');
    }

    public function downloadAttachment(string $token, AnnouncementAttachment $attachment): StreamedResponse
    {
        $recipient = $this->recipient($token);
        abort_unless((int) $attachment->announcement_id === (int) $recipient->announcement_id, 404);
        abort_unless((int) $attachment->company_id === (int) $recipient->company_id, 404);

        return response()->streamDownload(function () use ($attachment): void {
            echo Storage::disk($attachment->disk)->get($attachment->path);
        }, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
        ]);
    }

    private function recipient(string $token): AnnouncementRecipient
    {
        $recipient = AnnouncementRecipient::query()
            ->where('public_token', $token)
            ->with(['announcement.attachments'])
            ->first();

        abort_unless($recipient !== null, 404);

        return $recipient;
    }

    private function markRead(AnnouncementRecipient $recipient): void
    {
        if ($recipient->read_at === null) {
            $recipient->update(['read_at' => now()]);
        }

        $delivery = $recipient->deliveries()
            ->where('channel', AnnouncementChannel::InApp)
            ->first();

        if ($delivery !== null && $delivery->read_at === null) {
            $delivery->update([
                'status' => AnnouncementDeliveryStatus::Read,
                'read_at' => now(),
                'delivered_at' => $delivery->delivered_at ?? now(),
            ]);
        }
    }
}
