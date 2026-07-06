<?php

namespace App\Services\SalaryDeclaration;

use App\Models\Employee;

interface RendersSalaryDeclarationPdf
{
    public function render(Employee $employee, int $companyId): string;
}
