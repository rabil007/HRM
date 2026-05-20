<?php

namespace App\Support\EmployeeDocuments;

use App\Models\EmployeeDocument;
use Illuminate\Support\Facades\Storage;

class DocumentDeletionService
{
    public function delete(EmployeeDocument $document): void
    {
        if (! str_starts_with((string) $document->file_path, 'http')) {
            Storage::disk('public')->delete((string) $document->file_path);
        }

        $document->delete();
    }
}
