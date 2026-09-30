<?php

namespace App\Contracts\EmployeeDocuments;

use App\Support\EmployeeDocuments\DocumentAiExtractionResult;
use Illuminate\Http\UploadedFile;

interface DocumentAiExtractor
{
    public function extract(UploadedFile $file): DocumentAiExtractionResult;
}
