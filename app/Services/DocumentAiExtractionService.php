<?php

namespace App\Services;

use App\Contracts\EmployeeDocuments\DocumentAiExtractor;
use App\Support\EmployeeDocuments\DocumentAiExtractionResult;
use Illuminate\Http\UploadedFile;

final class DocumentAiExtractionService
{
    public function __construct(private DocumentAiExtractor $extractor) {}

    public function extract(UploadedFile $file): DocumentAiExtractionResult
    {
        return $this->extractor->extract($file);
    }
}
