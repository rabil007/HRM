<?php

namespace App\Support\Employees\Resources;

use App\Models\EmployeeDocument;

final class EmployeeDocumentResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(EmployeeDocument $document): array
    {
        return $document->toProfileArray();
    }
}
