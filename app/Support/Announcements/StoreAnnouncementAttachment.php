<?php

namespace App\Support\Announcements;

use App\Models\Announcement;
use App\Models\AnnouncementAttachment;
use App\Models\User;
use App\Support\Uploads\UploadedFileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StoreAnnouncementAttachment
{
    public function handle(Announcement $announcement, UploadedFile $file, User $user): AnnouncementAttachment
    {
        if (! $announcement->status->isEditable()) {
            throw ValidationException::withMessages([
                'attachments' => 'Attachments cannot be changed after publication.',
            ]);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());
        $allowedExtensions = config('announcements.attachments.allowed_extensions', []);
        $allowedMimes = config('announcements.attachments.allowed_mimes', []);
        $maxFile = (int) config('announcements.attachments.max_file_bytes', 10 * 1024 * 1024);
        $maxTotal = (int) config('announcements.attachments.max_total_bytes', 30 * 1024 * 1024);

        if (! in_array($extension, $allowedExtensions, true) || ! in_array($mime, $allowedMimes, true)) {
            throw ValidationException::withMessages([
                'attachments' => 'Unsupported attachment type.',
            ]);
        }

        if ($file->getSize() > $maxFile) {
            throw ValidationException::withMessages([
                'attachments' => 'Attachment exceeds the maximum file size.',
            ]);
        }

        $existingTotal = (int) $announcement->attachments()->sum('size_bytes');
        if ($existingTotal + $file->getSize() > $maxTotal) {
            throw ValidationException::withMessages([
                'attachments' => 'Total attachment size limit exceeded.',
            ]);
        }

        $disk = (string) config('announcements.attachments.disk', 'local');
        $storedName = Str::uuid()->toString().'.'.$extension;
        $directory = "announcements/{$announcement->company_id}/{$announcement->id}";
        $path = UploadedFileStorage::storeAs($file, $directory, $storedName, $disk);

        return AnnouncementAttachment::query()->create([
            'company_id' => $announcement->company_id,
            'announcement_id' => $announcement->id,
            'original_name' => $file->getClientOriginalName(),
            'stored_name' => $storedName,
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $mime,
            'size_bytes' => $file->getSize(),
            'uploaded_by' => $user->id,
        ]);
    }

    public function delete(AnnouncementAttachment $attachment): void
    {
        $announcement = $attachment->announcement;

        if ($announcement === null || ! $announcement->status->isEditable()) {
            throw ValidationException::withMessages([
                'attachments' => 'Attachments cannot be changed after publication.',
            ]);
        }

        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();
    }
}
