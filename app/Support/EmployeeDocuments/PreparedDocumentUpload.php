<?php

namespace App\Support\EmployeeDocuments;

use Illuminate\Http\UploadedFile;

final class PreparedDocumentUpload
{
    public function __construct(
        public UploadedFile $file,
        private ?string $temporaryPath = null,
    ) {}

    public function cleanup(): void
    {
        if ($this->temporaryPath !== null && is_file($this->temporaryPath)) {
            @unlink($this->temporaryPath);
        }
    }
}
