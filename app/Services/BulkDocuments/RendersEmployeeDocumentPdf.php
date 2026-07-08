<?php

namespace App\Services\BulkDocuments;

use App\Models\Employee;

interface RendersEmployeeDocumentPdf
{
    public function render(Employee $employee, int $companyId): string;
}
