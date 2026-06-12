<?php

namespace App\Support\Attendance;

use App\Support\Uploads\UploadedFileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class LeaveRequestAttachments
{
    /**
     * @return list<array{path: string, name: string, size: int, mime: string|null}>
     */
    public function store(UploadedFile $file, int $companyId, int $leaveRequestId): array
    {
        $path = UploadedFileStorage::store(
            $file,
            "leave-requests/{$companyId}/{$leaveRequestId}",
            'local',
        );

        return [[
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'size' => (int) $file->getSize(),
            'mime' => $file->getClientMimeType(),
        ]];
    }

    /**
     * @param  list<array{path?: string, name?: string, size?: int, mime?: string|null}>|null  $attachments
     */
    public function deleteFromStorage(?array $attachments): void
    {
        if ($attachments === null) {
            return;
        }

        foreach ($attachments as $attachment) {
            $path = $attachment['path'] ?? null;

            if (! is_string($path) || $path === '') {
                continue;
            }

            Storage::disk('local')->delete($path);
        }
    }

    /**
     * @param  list<array{path?: string, name?: string, size?: int, mime?: string|null}>|null  $attachments
     * @return list<array{path: string, name: string, size: int, mime: string|null, url: string}>
     */
    public function serializeForFrontend(?array $attachments, int $leaveRequestId): array
    {
        if ($attachments === null) {
            return [];
        }

        return collect($attachments)
            ->filter(fn (array $attachment) => filled($attachment['path'] ?? null))
            ->map(fn (array $attachment) => [
                'path' => (string) $attachment['path'],
                'name' => (string) ($attachment['name'] ?? 'Attachment'),
                'size' => (int) ($attachment['size'] ?? 0),
                'mime' => isset($attachment['mime']) ? (string) $attachment['mime'] : null,
                'url' => route('attendance.leave-requests.attachment', $leaveRequestId),
            ])
            ->values()
            ->all();
    }
}
