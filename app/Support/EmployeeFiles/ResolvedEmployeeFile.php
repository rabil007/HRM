<?php

namespace App\Support\EmployeeFiles;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class ResolvedEmployeeFile
{
    public function __construct(
        public string $disk,
        public string $path,
    ) {}

    public function get(): ?string
    {
        $contents = Storage::disk($this->disk)->get($this->path);

        return is_string($contents) ? $contents : null;
    }

    public function absolutePath(): string
    {
        return Storage::disk($this->disk)->path($this->path);
    }

    public function mimeType(): ?string
    {
        $mimeType = Storage::disk($this->disk)->mimeType($this->path);

        return is_string($mimeType) && $mimeType !== '' ? $mimeType : null;
    }

    public function size(): ?int
    {
        $size = Storage::disk($this->disk)->size($this->path);

        return is_int($size) ? $size : null;
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function download(string $name, array $headers = []): StreamedResponse
    {
        return Storage::disk($this->disk)->download($this->path, $name, $headers);
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function inlineResponse(string $name, array $headers = []): StreamedResponse
    {
        $safeName = str_replace(['"', "\r", "\n"], '', $name);

        return Storage::disk($this->disk)->response($this->path, $safeName, [
            'Content-Disposition' => 'inline; filename="'.$safeName.'"',
            ...$headers,
        ]);
    }
}
