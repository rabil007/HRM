<?php

namespace App\Http\Requests\Organization\BulkDocuments;

class DownloadBulkDocumentsRequest extends BulkDocumentActionRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('documents.download') ?? false;
    }
}
