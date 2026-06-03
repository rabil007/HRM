<?php

namespace App\Support\EmployeeDocuments;

use App\Services\DocumentPdfCompressionService;
use Illuminate\Http\UploadedFile;

class DocumentUploadOptimizer
{
    public function __construct(private DocumentPdfCompressionService $pdfCompression) {}

    public function prepare(UploadedFile $file): PreparedDocumentUpload
    {
        if (! $this->isPdf($file)) {
            return new PreparedDocumentUpload($file);
        }

        $sourcePath = $file->getRealPath();

        if ($sourcePath === false) {
            return new PreparedDocumentUpload($file);
        }

        $compressedPath = $this->pdfCompression->compress($sourcePath);

        if ($compressedPath === null) {
            return new PreparedDocumentUpload($file);
        }

        return new PreparedDocumentUpload(
            new UploadedFile(
                $compressedPath,
                $file->getClientOriginalName(),
                'application/pdf',
                null,
                true,
            ),
            $compressedPath,
        );
    }

    private function isPdf(UploadedFile $file): bool
    {
        $mimeType = $file->getMimeType();

        return $mimeType === 'application/pdf'
            || strtolower($file->getClientOriginalExtension()) === 'pdf';
    }
}
