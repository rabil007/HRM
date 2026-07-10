<?php

namespace App\Support\EmployeeTrainings;

use App\Models\EmployeeTraining;
use App\Models\EmployeeTrainingVersion;
use App\Support\EmployeeDocuments\DocumentUploadOptimizer;
use App\Support\Uploads\UploadedFileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StoresEmployeeTrainingCertificate
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
    public function store(
        UploadedFile $file,
        int $companyId,
        int $employeeId,
        ?int $trainingIndex = null,
        ?int $trainingId = null,
    ): array {
        $prepared = $this->optimizer->prepare($file);

        try {
            $path = $this->storeFile($prepared->file, $companyId, $employeeId, $trainingIndex, $trainingId);

            return $this->certificateAttributes($file, $prepared->file, $path);
        } finally {
            $prepared->cleanup();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function replace(
        EmployeeTraining $training,
        UploadedFile $file,
        int $companyId,
        int $employeeId,
        ?int $userId,
        array $data = [],
    ): EmployeeTraining {
        $prepared = $this->optimizer->prepare($file);

        try {
            return DB::transaction(function () use ($training, $prepared, $file, $companyId, $employeeId, $userId, $data) {
                if ($training->certificate_path !== null && $training->certificate_path !== '') {
                    EmployeeTrainingVersion::query()->create([
                        'employee_training_id' => $training->id,
                        'company_id' => $training->company_id,
                        'employee_id' => $training->employee_id,
                        'version' => (int) $training->current_version,
                        'file_path' => $training->certificate_path,
                        'original_filename' => $training->certificate_original_filename,
                        'mime_type' => $training->certificate_mime_type,
                        'size_bytes' => $training->certificate_size_bytes,
                        'checksum' => $training->certificate_checksum,
                        'replaced_by' => $userId,
                    ]);
                }

                $path = $this->storeFile(
                    $prepared->file,
                    $companyId,
                    $employeeId,
                    trainingId: $training->id,
                );

                $certificateAttributes = $this->certificateAttributes($file, $prepared->file, $path);

                $attributes = [
                    ...$certificateAttributes,
                    'current_version' => ((int) $training->current_version) + 1,
                    'replaced_at' => now(),
                ];

                if (array_key_exists('issue_date', $data)) {
                    $attributes['issue_date'] = $data['issue_date'] ?? null;
                }

                if (array_key_exists('expiry_date', $data)) {
                    $attributes['expiry_date'] = $data['expiry_date'] ?? null;
                }

                $training->update($attributes);

                return $training->refresh();
            });
        } finally {
            $prepared->cleanup();
        }
    }

    public function deleteForTraining(EmployeeTraining $training): void
    {
        $paths = $training->versions()
            ->pluck('file_path')
            ->filter(fn (?string $path): bool => $path !== null && $path !== '')
            ->all();

        if ($training->certificate_path !== null && $training->certificate_path !== '') {
            $paths[] = $training->certificate_path;
        }

        foreach (array_unique($paths) as $path) {
            Storage::disk('public')->delete($path);
        }
    }

    public function deletePath(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    private function storeFile(
        UploadedFile $file,
        int $companyId,
        int $employeeId,
        ?int $trainingIndex = null,
        ?int $trainingId = null,
    ): string {
        $logContext = [
            'upload_module' => 'employee_training_certificate',
            'employee_id' => $employeeId,
        ];

        if ($trainingIndex !== null) {
            $logContext['training_index'] = $trainingIndex;
        }

        if ($trainingId !== null) {
            $logContext['training_id'] = $trainingId;
        }

        return UploadedFileStorage::storePublicly(
            $file,
            "employees/{$companyId}/training-certificates",
            [
                'disk' => 'public',
                'log_context' => $logContext,
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
