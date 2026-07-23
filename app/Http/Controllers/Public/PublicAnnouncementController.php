<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\AnnouncementAttachment;
use App\Models\AnnouncementRecipient;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicAnnouncementController extends Controller
{
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
}
