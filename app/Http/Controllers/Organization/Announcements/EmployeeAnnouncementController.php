<?php

namespace App\Http\Controllers\Organization\Announcements;

use App\Enums\AnnouncementChannel;
use App\Enums\AnnouncementDeliveryStatus;
use App\Enums\AnnouncementStatus;
use App\Http\Controllers\Controller;
use App\Models\AnnouncementRecipient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeAnnouncementController extends Controller
{
    public function show(Request $request, AnnouncementRecipient $recipient): Response
    {
        $user = $request->user();
        abort_unless($user !== null && (int) $recipient->user_id === (int) $user->id, 404);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $recipient->company_id === $companyId, 404);

        $announcement = $recipient->announcement;
        abort_unless($announcement !== null, 404);
        abort_unless(in_array($announcement->status, [
            AnnouncementStatus::Published,
            AnnouncementStatus::PartiallyDelivered,
            AnnouncementStatus::Expired,
        ], true), 404);

        $announcement->loadMissing('attachments');

        if ($recipient->read_at === null) {
            $recipient->update(['read_at' => now()]);
        }

        $delivery = $recipient->deliveries()->where('channel', AnnouncementChannel::InApp)->first();
        if ($delivery !== null && $delivery->read_at === null) {
            $delivery->update([
                'status' => AnnouncementDeliveryStatus::Read,
                'read_at' => now(),
                'delivered_at' => $delivery->delivered_at ?? now(),
            ]);
        }

        return Inertia::render('organization/announcements/employee-show', [
            'announcement' => [
                'title' => $announcement->title,
                'body_html' => $announcement->body_html,
                'priority' => $announcement->priority->label(),
                'category' => $announcement->category->label(),
                'published_at' => $announcement->published_at?->toIso8601String(),
                'attachments' => $announcement->attachments->map(fn ($attachment): array => [
                    'id' => $attachment->id,
                    'original_name' => $attachment->original_name,
                ])->values()->all(),
            ],
            'recipient_id' => $recipient->id,
        ]);
    }

    public function feed(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        $companyId = (int) $request->attributes->get('current_company_id');

        $recipients = AnnouncementRecipient::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->whereHas('announcement', fn ($q) => $q->whereIn('status', [
                AnnouncementStatus::Published->value,
                AnnouncementStatus::PartiallyDelivered->value,
            ]))
            ->whereHas('deliveries', fn ($q) => $q->where('channel', AnnouncementChannel::InApp->value)
                ->whereNotIn('status', [AnnouncementDeliveryStatus::Skipped->value]))
            ->with(['announcement:id,title,body_html,priority,published_at'])
            ->latest('id')
            ->limit(20)
            ->get();

        $unreadCount = $recipients->whereNull('read_at')->count();

        return response()->json([
            'unread_count' => $unreadCount,
            'items' => $recipients->map(fn (AnnouncementRecipient $recipient): array => [
                'id' => $recipient->id,
                'title' => $recipient->announcement?->title,
                'preview' => str($recipient->announcement?->body_html ?? '')->stripTags()->limit(100)->toString(),
                'priority' => $recipient->announcement?->priority->value,
                'published_at' => $recipient->announcement?->published_at?->toIso8601String(),
                'read_at' => $recipient->read_at?->toIso8601String(),
                'url' => route('organization.announcements.inbox.show', $recipient),
            ])->values()->all(),
        ]);
    }

    public function markRead(Request $request, AnnouncementRecipient $recipient): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null && (int) $recipient->user_id === (int) $user->id, 404);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $recipient->company_id === $companyId, 404);

        if ($recipient->read_at === null) {
            $recipient->update(['read_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }
}
