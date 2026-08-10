<?php

namespace App\Support\Vessels;

use App\Models\Vessel;
use App\Support\EmployeeDocuments\DocumentUploadOptimizer;
use App\Support\Uploads\UploadedFileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class StoresVesselCertificate
{
    public function __construct(private DocumentUploadOptimizer $optimizer) {}

    /**
     * @return array{
     *     certificate_path: string,
     *     certificate_original_filename: string,
     *     certificate_mime_type: string|null,
     *     certificate_size_bytes: int|null,
     *     certificate_checksum: string
     * }
     */
    public function store(UploadedFile $file, int $vesselId): array
    {
        $prepared = $this->optimizer->prepare($file);

        try {
            $path = $this->storeFile($prepared->file, $vesselId);

            return $this->certificateAttributes($file, $prepared->file, $path);
        } finally {
            $prepared->cleanup();
        }
    }

    /**
     * @return array{
     *     certificate_path: string,
     *     certificate_original_filename: string,
     *     certificate_mime_type: string|null,
     *     certificate_size_bytes: int|null,
     *     certificate_checksum: string
     * }
     */
    public function replace(Vessel $vessel, UploadedFile $file): array
    {
        $prepared = $this->optimizer->prepare($file);

        try {
            $previousPath = $vessel->certificate_path;
            $path = $this->storeFile($prepared->file, (int) $vessel->id);
            $attributes = $this->certificateAttributes($file, $prepared->file, $path);

            if ($previousPath !== null && $previousPath !== '' && $previousPath !== $path) {
                $this->deletePath($previousPath);
            }

            return $attributes;
        } finally {
            $prepared->cleanup();
        }
    }

    public function deletePath(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    private function storeFile(UploadedFile $file, int $vesselId): string
    {
        return UploadedFileStorage::storePublicly(
            $file,
            "vessels/{$vesselId}/certificates",
            [
                'disk' => 'public',
                'log_context' => [
                    'upload_module' => 'vessel_certificate',
                    'vessel_id' => $vesselId,
                ],
            ],
        );
    }

    /**
     * @return array{
     *     certificate_path: string,
     *     certificate_original_filename: string,
     *     certificate_mime_type: string|null,
     *     certificate_size_bytes: int|null,
     *     certificate_checksum: string
     * }
     */
    private function certificateAttributes(
        UploadedFile $originalFile,
        UploadedFile $storedFile,
        string $path,
    ): array {
        return [
            'certificate_path' => $path,
            'certificate_original_filename' => $originalFile->getClientOriginalName(),
            'certificate_mime_type' => $storedFile->getMimeType(),
            'certificate_size_bytes' => $storedFile->getSize(),
            'certificate_checksum' => hash_file('sha256', $storedFile->getRealPath() ?: '') ?: '',
        ];
    }
}
