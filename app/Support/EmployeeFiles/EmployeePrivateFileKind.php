<?php

namespace App\Support\EmployeeFiles;

enum EmployeePrivateFileKind: string
{
    case Document = 'document';
    case TrainingCertificate = 'training_certificate';

    public function directoryPrefix(int $companyId): string
    {
        return match ($this) {
            self::Document => "employee-documents/{$companyId}/",
            self::TrainingCertificate => "employees/{$companyId}/training-certificates/",
        };
    }
}
