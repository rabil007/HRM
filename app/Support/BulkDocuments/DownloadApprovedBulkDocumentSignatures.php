<?php

namespace App\Support\BulkDocuments;

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Models\BulkDocumentSignatureRequest;
use App\Services\DocumentMergeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

final class DownloadApprovedBulkDocumentSignatures
{
    public function __construct(
        private DocumentMergeService $merge,
    ) {}

    /**
     * @param  list<int>  $signatureRequestIds
     */
    public function streamZip(
        int $companyId,
        string $documentTypeKey,
        array $signatureRequestIds,
    ): StreamedResponse {
        $requests = $this->eligibleRequests($companyId, $documentTypeKey, $signatureRequestIds);

        abort_if($requests->isEmpty(), 404, 'No downloadable files found.');

        $tmpPath = tempnam(sys_get_temp_dir(), 'approved_signed_zip_');

        abort_if($tmpPath === false, 500, 'Could not prepare download.');

        $zip = new ZipArchive;

        abort_unless($zip->open($tmpPath, ZipArchive::OVERWRITE) === true, 500, 'Could not create archive.');

        $added = 0;
        $usedNames = [];

        foreach ($requests as $request) {
            $absolutePath = BulkDocumentSignatureStorage::path((string) $request->signed_pdf_path);

            if (! is_readable($absolutePath)) {
                continue;
            }

            $entryName = $this->uniqueEntryName($this->entryFilename($request), $usedNames);
            $zip->addFile($absolutePath, $entryName);
            $added++;
        }

        $zip->close();

        abort_if($added === 0, 404, 'No downloadable files found.');

        $contentLength = filesize($tmpPath);
        $downloadName = $this->archiveDownloadName($documentTypeKey);

        return response()->streamDownload(function () use ($tmpPath): void {
            $handle = fopen($tmpPath, 'rb');

            if ($handle !== false) {
                fpassthru($handle);
                fclose($handle);
            }

            @unlink($tmpPath);
        }, $downloadName, [
            'Content-Type' => 'application/zip',
            'Content-Length' => $contentLength !== false ? (string) $contentLength : null,
        ]);
    }

    /**
     * @param  list<int>  $signatureRequestIds
     */
    public function streamMergedPdf(
        int $companyId,
        string $documentTypeKey,
        array $signatureRequestIds,
    ): StreamedResponse {
        $requests = $this->eligibleRequests($companyId, $documentTypeKey, $signatureRequestIds);

        abort_if($requests->isEmpty(), 404, 'No downloadable files found.');

        /** @var list<string> $paths */
        $paths = $requests
            ->map(fn (BulkDocumentSignatureRequest $request): string => BulkDocumentSignatureStorage::path((string) $request->signed_pdf_path))
            ->filter(fn (string $path): bool => is_readable($path))
            ->values()
            ->all();

        abort_if($paths === [], 404, 'No downloadable files found.');

        return $this->merge->streamMergedPdfFromPaths(
            $paths,
            $this->mergedDownloadName($documentTypeKey),
        );
    }

    /**
     * @param  list<int>  $signatureRequestIds
     * @return Collection<int, BulkDocumentSignatureRequest>
     */
    private function eligibleRequests(
        int $companyId,
        string $documentTypeKey,
        array $signatureRequestIds,
    ): Collection {
        $requests = BulkDocumentSignatureRequest::query()
            ->with(['employee:id,name,employee_no'])
            ->where('company_id', $companyId)
            ->where('document_type_key', $documentTypeKey)
            ->where('status', BulkDocumentSignatureRequestStatus::Approved)
            ->whereNotNull('signed_pdf_path')
            ->whereKey($signatureRequestIds)
            ->get()
            ->keyBy('id');

        return collect($signatureRequestIds)
            ->map(fn (int $id): ?BulkDocumentSignatureRequest => $requests->get($id))
            ->filter(fn (?BulkDocumentSignatureRequest $request): bool => $request !== null
                && BulkDocumentSignatureStorage::exists((string) $request->signed_pdf_path))
            ->values();
    }

    private function entryFilename(BulkDocumentSignatureRequest $request): string
    {
        $employee = $request->employee;
        $number = $this->sanitizeSegment(
            (string) ($employee?->employee_no ?: $employee?->id ?: $request->id),
            (string) $request->id,
        );
        $name = $this->sanitizeSegment((string) ($employee?->name ?: 'employee'), 'employee');

        return "{$number}-{$name}.pdf";
    }

    /**
     * @param  array<string, int>  $usedNames
     */
    private function uniqueEntryName(string $filename, array &$usedNames): string
    {
        $base = $filename;
        $extension = '';

        if (str_contains($filename, '.')) {
            $extension = '.'.pathinfo($filename, PATHINFO_EXTENSION);
            $base = pathinfo($filename, PATHINFO_FILENAME);
        }

        $candidate = $filename;
        $suffix = 1;

        while (isset($usedNames[$candidate])) {
            $suffix++;
            $candidate = "{$base}-{$suffix}{$extension}";
        }

        $usedNames[$candidate] = 1;

        return $candidate;
    }

    private function archiveDownloadName(string $documentTypeKey): string
    {
        $slug = Str::slug(BulkDocumentTypeRegistry::find($documentTypeKey)['label']) ?: 'document';

        return "{$slug}-approved-signed-".now()->format('Y-m-d').'.zip';
    }

    private function mergedDownloadName(string $documentTypeKey): string
    {
        $slug = Str::slug(BulkDocumentTypeRegistry::find($documentTypeKey)['label']) ?: 'document';

        return "{$slug}-approved-signed-".now()->format('Ymd').'.pdf';
    }

    private function sanitizeSegment(string $value, string $fallback): string
    {
        $segment = preg_replace('/[^a-zA-Z0-9\-]+/', '-', trim($value)) ?? '';
        $segment = trim($segment, '-');

        return $segment !== '' ? $segment : $fallback;
    }
}
