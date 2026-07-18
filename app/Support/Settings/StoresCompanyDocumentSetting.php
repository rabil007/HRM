<?php

namespace App\Support\Settings;

use App\Models\CompanyDocumentSetting;
use App\Support\Uploads\UploadedFileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class StoresCompanyDocumentSetting
{
    private const STORAGE_DIR = 'company-document-settings';

    /**
     * @param  array{
     *     signatory_name?: string|null,
     *     signatory_title?: string|null,
     *     footer_text?: string|null,
     *     effective_from?: string|null,
     *     effective_to?: string|null,
     *     remove_signature?: bool,
     *     remove_stamp?: bool,
     * }  $data
     * @param  array{signature?: UploadedFile|null, stamp?: UploadedFile|null}  $files
     */
    public function update(
        int $companyId,
        string $documentType,
        array $data,
        array $files,
        ?int $updatedBy,
    ): CompanyDocumentSetting {
        abort_unless(CompanyDocumentType::isValid($documentType), 404);

        $setting = CompanyDocumentSetting::query()->firstOrNew([
            'company_id' => $companyId,
            'document_type' => $documentType,
        ]);

        $previousSignature = $setting->signature_path;
        $previousStamp = $setting->stamp_path;
        $storedPaths = [];

        try {
            return DB::transaction(function () use (
                $setting,
                $data,
                $files,
                $updatedBy,
                $companyId,
                &$storedPaths,
                $previousSignature,
                $previousStamp,
            ): CompanyDocumentSetting {
                $setting->signatory_name = $data['signatory_name'] ?? null;
                $setting->signatory_title = $data['signatory_title'] ?? null;
                $setting->footer_text = $data['footer_text'] ?? null;
                $setting->effective_from = $data['effective_from'] ?? null;
                $setting->effective_to = $data['effective_to'] ?? null;
                $setting->updated_by = $updatedBy;

                if (($data['remove_signature'] ?? false) === true) {
                    $setting->signature_path = null;
                }

                if (($data['remove_stamp'] ?? false) === true) {
                    $setting->stamp_path = null;
                }

                if (($files['signature'] ?? null) instanceof UploadedFile) {
                    $storedPaths[] = $setting->signature_path = $this->storeFile(
                        $files['signature'],
                        $companyId,
                        'signature',
                    );
                }

                if (($files['stamp'] ?? null) instanceof UploadedFile) {
                    $storedPaths[] = $setting->stamp_path = $this->storeFile(
                        $files['stamp'],
                        $companyId,
                        'stamp',
                    );
                }

                $setting->save();

                $this->deleteIfUnused($previousSignature, $setting->signature_path);
                $this->deleteIfUnused($previousStamp, $setting->stamp_path);

                return $setting->refresh();
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                if (is_string($path) && $path !== '' && Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
            }

            throw $exception;
        }
    }

    private function storeFile(UploadedFile $file, int $companyId, string $kind): string
    {
        $extension = $file->getClientOriginalExtension() ?: $file->extension();
        $filename = $kind.'-'.Str::uuid().'.'.strtolower((string) $extension);
        $directory = self::STORAGE_DIR.'/'.$companyId;

        return UploadedFileStorage::storeAs($file, $directory, $filename, 'public');
    }

    private function deleteIfUnused(?string $previousPath, ?string $currentPath): void
    {
        if (
            ! is_string($previousPath)
            || $previousPath === ''
            || $previousPath === $currentPath
        ) {
            return;
        }

        $stillReferenced = CompanyDocumentSetting::query()
            ->where(function ($query) use ($previousPath): void {
                $query->where('signature_path', $previousPath)
                    ->orWhere('stamp_path', $previousPath);
            })
            ->exists();

        if ($stillReferenced) {
            return;
        }

        if (Storage::disk('public')->exists($previousPath)) {
            Storage::disk('public')->delete($previousPath);
        }
    }
}
