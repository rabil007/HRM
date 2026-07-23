<?php

namespace App\Http\Controllers\Organization\Announcements;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AnnouncementAttachment;
use App\Support\Announcements\StoreAnnouncementAttachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnnouncementAttachmentController extends Controller
{
    public function store(
        Request $request,
        Announcement $announcement,
        StoreAnnouncementAttachment $store,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $announcement->company_id === $companyId, 404);

        $user = $request->user();
        abort_unless($user !== null, 403);

        $request->validate([
            'attachment' => ['required', 'file'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('attachment');
        $store->handle($announcement, $file, $user);

        return back()->with('success', 'Attachment uploaded.');
    }

    public function destroy(
        Request $request,
        Announcement $announcement,
        AnnouncementAttachment $attachment,
        StoreAnnouncementAttachment $store,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $announcement->company_id === $companyId, 404);
        abort_unless((int) $attachment->announcement_id === (int) $announcement->id, 404);
        abort_unless((int) $attachment->company_id === $companyId, 404);

        $store->delete($attachment);

        return back()->with('success', 'Attachment removed.');
    }

    public function download(
        Request $request,
        Announcement $announcement,
        AnnouncementAttachment $attachment,
    ): StreamedResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $announcement->company_id === $companyId, 404);
        abort_unless((int) $attachment->announcement_id === (int) $announcement->id, 404);
        abort_unless((int) $attachment->company_id === $companyId, 404);

        activity()
            ->useLog('announcements')
            ->event('attachment_downloaded')
            ->causedBy($request->user())
            ->performedOn($announcement)
            ->withProperties(['attachment_id' => $attachment->id])
            ->tap(fn ($activity) => $activity->company_id = $companyId)
            ->log('Announcement attachment downloaded');

        return response()->streamDownload(function () use ($attachment): void {
            echo Storage::disk($attachment->disk)->get($attachment->path);
        }, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
        ]);
    }
}
