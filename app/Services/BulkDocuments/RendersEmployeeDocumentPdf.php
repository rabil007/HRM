<?php

namespace App\Services\BulkDocuments;

use App\Models\Employee;

interface RendersEmployeeDocumentPdf
{
    /**
     * @param  array{signed_name?: string, signature_image_url?: string, signed_date?: string}|null  $signature
     */
    public function render(Employee $employee, int $companyId, ?array $signature = null): string;
}
