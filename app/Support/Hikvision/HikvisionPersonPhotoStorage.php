<?php

namespace App\Support\Hikvision;

use App\Models\HikvisionPerson;
use App\Support\Uploads\UploadedFileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class HikvisionPersonPhotoStorage
{
    private const DISK = 'public';

    private const DIRECTORY = 'hikvision/persons';

    public static function stableRemoteKey(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : null;
    }

    public static function syncFromRemoteUrl(HikvisionPerson $person, ?string $remoteUrl): void
    {
        if (! filled($remoteUrl)) {
            self::delete($person);

            return;
        }

        $remoteKey = self::stableRemoteKey($remoteUrl);

        if (
            $remoteKey !== null
            && $person->photo_remote_key === $remoteKey
            && filled($person->photo_path)
            && Storage::disk(self::DISK)->exists($person->photo_path)
        ) {
            return;
        }

        try {
            $response = Http::timeout(15)->get($remoteUrl);

            if (! $response->successful()) {
                return;
            }

            $contentType = (string) ($response->header('Content-Type') ?? '');

            if ($contentType !== '' && ! str_starts_with($contentType, 'image/')) {
                return;
            }

            $extension = self::extensionFromContentType($contentType)
                ?? self::extensionFromRemoteKey($remoteKey)
                ?? 'jpg';
            $storedPath = self::pathForPerson($person->person_id, $extension);

            self::deleteStoredFile($person->photo_path, $storedPath);

            Storage::disk(self::DISK)->put($storedPath, $response->body());

            $person->forceFill([
                'photo_path' => $storedPath,
                'photo_remote_key' => $remoteKey,
            ])->saveQuietly();
        } catch (\Throwable $exception) {
            Log::warning('Failed to cache Hikvision person photo.', [
                'person_id' => $person->person_id,
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    public static function applyUploadedFile(HikvisionPerson $person, UploadedFile $file, ?string $remoteUrl = null): void
    {
        $extension = $file->getClientOriginalExtension() ?: 'jpg';
        $storedPath = self::pathForPerson($person->person_id, $extension);

        self::deleteStoredFile($person->photo_path, $storedPath);

        UploadedFileStorage::storeAs(
            $file,
            self::DIRECTORY,
            basename($storedPath),
            ['disk' => self::DISK],
        );

        $person->forceFill([
            'photo_path' => $storedPath,
            'photo_remote_key' => filled($remoteUrl) ? self::stableRemoteKey($remoteUrl) : $person->photo_remote_key,
        ])->saveQuietly();
    }

    public static function delete(HikvisionPerson $person): void
    {
        self::deleteStoredFile($person->photo_path);

        if ($person->photo_path !== null || $person->photo_remote_key !== null) {
            $person->forceFill([
                'photo_path' => null,
                'photo_remote_key' => null,
            ])->saveQuietly();
        }
    }

    private static function pathForPerson(string $personId, string $extension): string
    {
        return self::DIRECTORY.'/'.$personId.'.'.ltrim($extension, '.');
    }

    private static function deleteStoredFile(?string $currentPath, ?string $nextPath = null): void
    {
        if (! filled($currentPath)) {
            return;
        }

        if ($nextPath !== null && $currentPath === $nextPath) {
            return;
        }

        Storage::disk(self::DISK)->delete($currentPath);
    }

    private static function extensionFromContentType(string $contentType): ?string
    {
        return match (strtolower(trim(explode(';', $contentType)[0]))) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => null,
        };
    }

    private static function extensionFromRemoteKey(?string $remoteKey): ?string
    {
        if ($remoteKey === null) {
            return null;
        }

        $extension = pathinfo($remoteKey, PATHINFO_EXTENSION);

        return is_string($extension) && $extension !== '' ? $extension : null;
    }
}
